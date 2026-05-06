<?php
/**
 * Синхронизация контрагентов из 1С в Bitrix24 CRM
 * - Проверка по УИД (UF_CRM_UID)
 * - Обновление реквизитов, флагов покупателя/поставщика
 * 
 * Запуск: по cron каждый день
 */

// ФИКС ДЛЯ CLI: определяем DOCUMENT_ROOT ДО любого require
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");

// Авторизация как администратор для корректной работы фильтров Битрикс в CLI
if (PHP_SAPI === 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        $USER = new \CUser();
        $USER->Authorize(1);  // ID=1 — администратор по умолчанию
        // Или ваш пользователь: $USER->Authorize(6);
    }
}

// Дальше можно закрывать сессию, если нужно
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('crm');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

// 🔧 Увеличиваем лимиты
set_time_limit(3600);
ini_set('memory_limit', '2G');

$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);
$oneC = new OneCClient($config);

Logger::info('=== НАЧАЛО СИНХРОНИЗАЦИИ КОНТРАГЕНТОВ ИЗ 1С ===');

// 🔹 Получаем контрагентов из 1С (только изменённые/новые через /partners)
$partnersFrom1C = $oneC->getAllPartners(true);

if ($partnersFrom1C === false || !is_array($partnersFrom1C)) {
    Logger::error('Не удалось получить контрагентов из 1С');
    exit;
}

if (empty($partnersFrom1C)) {
    Logger::info('Список контрагентов пуст');
    exit;
}

// 🔧 Фильтрация: убираем помеченные на удаление
$partnersFrom1C = array_filter($partnersFrom1C, fn($p) => 
    ($p['ПометкаУдаления'] ?? 'Нет') === 'Нет'
);
Logger::info('После фильтрации удалённых', ['count' => count($partnersFrom1C)]);

$created = 0;
$updated = 0;
$errors = 0;

Logger::info('Начало обработки', ['total_partners' => count($partnersFrom1C)]);

foreach ($partnersFrom1C as $index => $partner1C) {
    try {
        $uid = $partner1C['УИД'] ?? '';
        $name = $partner1C['Наименование'] ?? '';
        $partnerType = mb_strtolower(trim($partner1C['ВидКонтрагента'] ?? ''));
        
        if (empty($uid)) {
            Logger::warning('Пропуск: пустой УИД', ['name' => $name]);
            continue;
        }
        
        $isPhysical = in_array($partnerType, ['физическое лицо', 'физ. лицо']);
        $entityId = null;
        $action = null;
        
        if ($isPhysical) {
            // 👤 Контакт
            $result = syncContact($uid, $partner1C);
            $entityId = $result['id'] ?? null;
            $action = $result['action'] ?? null;
            $entityType = 'contact';
        } else {
            // 🏢 Компания
            $existingCompany = findCompanyByUid($uid);
            if ($existingCompany) {
                $success = updateCompany($existingCompany['ID'], $partner1C);
                if ($success) {
                    $entityId = $existingCompany['ID'];
                    $action = 'updated';
                }
            } else {
                $entityId = createCompany($partner1C);
                if ($entityId) $action = 'created';
            }
            $entityType = 'company';
        }
        
        // 🔹 Реквизиты (только для компаний)
        if ($entityId && $entityType === 'company') {
            $requisiteId = getOrCreateCompanyRequisite($entityId, $partner1C);
            if ($requisiteId && !empty($partner1C['МассивСчетов'])) {
                $bankStats = syncBankDetailsToRequisite($requisiteId, $partner1C);
                Logger::info('Банковские счета', [
                    'company_id' => $entityId,
                    'synced' => $bankStats['synced'],
                    'errors' => $bankStats['errors']
                ]);
            }
        }

        // 🔹 Статистика + лог
        if ($entityId) {
            if ($action === 'updated') $updated++;
            else $created++;
        } else {
            $errors++;
            Logger::warning("✗ Ошибка {$entityType}", ['uid' => $uid, 'name' => $name]);
        }
        
    } catch (\Exception $e) {
        $errors++;
        Logger::error('Исключение', [
            'uid' => $partner1C['УИД'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
    }
    
    usleep(10000);
    if (($index + 1) % 100 === 0) {
        Logger::info('Прогресс', ['processed' => $index + 1, 'created' => $created, 'updated' => $updated, 'errors' => $errors]);
    }
}

// Финальный отчёт
Logger::info('=== ЗАВЕРШЕНИЕ СИНХРОНИЗАЦИИ КОНТРАГЕНТОВ ===', [
    'created' => $created,
    'updated' => $updated,
    'errors' => $errors,
    'total_processed' => count($partnersFrom1C)
]);

// ===== ФУНКЦИИ =====

/**
 * Поиск компании по УИД из 1С (UF_CRM_UID)
 */
function findCompanyByUid($uid) {
    if (empty($uid)) {
        return false;
    }
    
    $res = \CCrmCompany::GetListEx(
        [],
        [
            '=UF_CRM_UID' => $uid,  // ← Ваше пользовательское поле
            'CHECK_PERMISSIONS' => 'N'
        ],
        false,
        false,
        ['ID', 'TITLE', 'UF_CRM_UID', 'UF_CRM_INN', 'UF_CRM_CODE_1C']
    );
    
    return $res->Fetch();
}

/**
 * Маппинг вида контрагента из 1С в значение UF-поля Bitrix
 * 
 * @param string $viewFrom1C Значение из 1С: "Юридическое лицо", "ИП", etc.
 * @return string ID значения enumeration-поля в Bitrix
 */
function mapPartnerTypeToUF($viewFrom1C) {
    $view = trim($viewFrom1C ?? '');
    
    // Маппинг значений
    $mapping = [
        'юридическое лицо' => '169',
        'индивидуальный предприниматель' => '170',
        'ип' => '170',
        'физическое лицо' => '171',
        'физ. лицо' => '171',
    ];
    
    $key = mb_strtolower($view);
    return $mapping[$key] ?? '169'; // По умолчанию — Юр. лицо
}

/**
 * Создание новой компании
 */
function createCompany($partner1C) {
    global $APPLICATION;
    
    // 🔧 Маппинг вида контрагента
    $partnerTypeUF = mapPartnerTypeToUF($partner1C['ВидКонтрагента'] ?? '');
    
    $fields = [
        'TITLE' => trim($partner1C['Наименование'] ?? ''),
        'COMPANY_TYPE' => 'CLIENT',
        'SOURCE_ID' => '1C',
        'OPENED' => 'Y',
        
        // 🔧 ОБЯЗАТЕЛЬНОЕ enumeration-поле
        'UF_CRM_1769334519512' => $partnerTypeUF,
        
        // Пользовательские поля
        'UF_CRM_UID' => $partner1C['УИД'] ?? '',
        'UF_CRM_CODE_1C' => $partner1C['Код'] ?? '',
        'UF_CRM_INN' => $partner1C['ИНН'] ?? '',
        'UF_CRM_KPP' => $partner1C['КПП'] ?? '',
    ];
    
    // Контактные данные
    if (!empty($partner1C['email'])) {
        $fields['EMAIL'] = [['VALUE' => $partner1C['email'], 'VALUE_TYPE' => 'WORK']];
    }
    if (!empty($partner1C['phone'])) {
        $fields['PHONE'] = [['VALUE' => $partner1C['phone'], 'VALUE_TYPE' => 'WORK']];
    }
    
    // Флаги покупатель/поставщик
    $fields['UF_CRM_1773409218'] = ($partner1C['Покупатель'] ?? '') === 'Да' ? 'Да' : 'Нет';
    $fields['UF_CRM_1773409227'] = ($partner1C['Поставщик'] ?? '') === 'Да' ? 'Да' : 'Нет';
    $fields['UF_CRM_1773409237'] = ($partner1C['ПрочиеОтношения'] ?? '') === 'Да' ? 'Да' : 'Нет';
    $company = new \CCrmCompany();
    $companyId = $company->Add($fields, false, false);
    
    if (!$companyId) {
        $ex = $APPLICATION ? $APPLICATION->GetException() : null;
        Logger::error('❌ CCrmCompany::Add FAILED', [
            'exception' => $ex ? $ex->GetString() : 'null',
            'title' => $fields['TITLE'],
            'uf_value' => $partnerTypeUF
        ]);
        return false;
    }
    
    return $companyId;
}

/**
 * Обновление существующей компании
 */
function updateCompany($companyId, $partner1C) {
    global $APPLICATION;

    // 🔧 Маппинг вида контрагента
    $partnerTypeUF = mapPartnerTypeToUF($partner1C['ВидКонтрагента'] ?? '');
    
    $fields = [
        'TITLE' => trim($partner1C['Наименование'] ?? ''),
        
        // 🔧 ОБЯЗАТЕЛЬНОЕ enumeration-поле
        'UF_CRM_1769334519512' => $partnerTypeUF,
        
        // Пользовательские поля
        'UF_CRM_UID' => $partner1C['УИД'] ?? '',
        'UF_CRM_CODE_1C' => $partner1C['Код'] ?? '',
        'UF_CRM_INN' => $partner1C['ИНН'] ?? '',
        'UF_CRM_KPP' => $partner1C['КПП'] ?? '',
    ];
    
    // Контактные данные
    if (!empty($partner1C['email'])) {
        $fields['EMAIL'] = [['VALUE' => $partner1C['email'], 'VALUE_TYPE' => 'WORK']];
    }
    if (!empty($partner1C['phone'])) {
        $fields['PHONE'] = [['VALUE' => $partner1C['phone'], 'VALUE_TYPE' => 'WORK']];
    }
    
    // Флаги покупатель/поставщик
    $fields['UF_CRM_1773409218'] = ($partner1C['Покупатель'] ?? '') === 'Да' ? 'Да' : 'Нет';
    $fields['UF_CRM_1773409227'] = ($partner1C['Поставщик'] ?? '') === 'Да' ? 'Да' : 'Нет';
    $fields['UF_CRM_1773409237'] = ($partner1C['ПрочиеОтношения'] ?? '') === 'Да' ? 'Да' : 'Нет';
    
    $company = new \CCrmCompany();
    $result = $company->Update($companyId, $fields, false, false);
    
    if (!$result) {
        $ex = $APPLICATION ? $APPLICATION->GetException() : null;
        Logger::error('❌ CCrmCompany::Update FAILED', [
            'company_id' => $companyId,
            'exception' => $ex ? $ex->GetString() : 'null'
        ]);
        return false;
    }
    
    return true;
}

/**
 * Получение ID реквизита компании в Bitrix24
 * - Поиск по ИНН (RQ_INN) + ENTITY_ID
 * - Если найден существующий → возвращаем его ID (без обновлений!)
 * - Если не найден → создаём новый
 */
function getOrCreateCompanyRequisite($companyId, $partner1C) {
    // Получаем ИНН из данных 1С
    $inn = trim($partner1C['ИНН'] ?? '');
    $uid = $partner1C['УИД'] ?? '';
    $xmlIdExpected = !empty($uid) ? '1C_REQ_' . $uid : '';
    
    // Ищем существующий реквизит по ENTITY_ID + ИНН
    $listResult = \Bitrix\Main\Web\Json::decode(
        sendBitrixRequest('crm.requisite.list', [
            'filter' => [
                'ENTITY_TYPE_ID' => 4,  // Company в REST API
                'ENTITY_ID' => $companyId,
                'RQ_INN' => $inn,
            ],
            'select' => ['ID', 'RQ_INN', 'PRESET_ID', 'NAME'],
            'order' => ['DATE_CREATE' => 'DESC'],
            'limit' => 1
        ])
    );
    
    // Если нашли — возвращаем ID, НИЧЕГО не обновляем
    if (!empty($listResult['result'][0]['ID'])) {
        $requisite = $listResult['result'][0];
        return $requisite['ID'];
    }
    
    // Не нашли — создаём новый реквизит
    $partnerType = mb_strtolower(trim($partner1C['ВидКонтрагента'] ?? ''));
    $presetId = in_array($partnerType, ['индивидуальный предприниматель', 'ип']) ? 2 : 1;
    
    $fields = [
        'ENTITY_TYPE_ID' => 4,
        'ENTITY_ID' => $companyId,
        'RQ_TYPE' => 1,
        'PRESET_ID' => $presetId,
        'NAME' => trim($partner1C['Наименование'] ?? 'Реквизиты'),
        'XML_ID' => $xmlIdExpected,  // Сохраняем для будущего
        'COUNTRY_ID' => 1,
        
        // Базовые данные
        'RQ_INN' => $inn,
        'RQ_KPP' => $partner1C['КПП'] ?? '',
        'RQ_OGRN' => $partner1C['ОГРН'] ?? '',
        'RQ_OGRNIP' => $partner1C['ОГРНИП'] ?? '',
    ];
    
    $addResult = \Bitrix\Main\Web\Json::decode(
        sendBitrixRequest('crm.requisite.add', ['fields' => $fields])
    );
    
    if (!empty($addResult['result']) && is_numeric($addResult['result'])) {
        Logger::info('Реквизит создан', [
            'company_id' => $companyId,
            'requisite_id' => $addResult['result'],
            'preset_id' => $presetId,
            'inn' => $inn
        ]);
        return $addResult['result'];
    }
    
    Logger::error('Не удалось создать реквизит', [
        'company_id' => $companyId,
        'error' => $addResult['error_description'] ?? 'unknown',
        'fields_sent' => $fields
    ]);
    
    return false;
}

/**
 * Отправка REST-запроса к Bitrix24 с использованием конфига
 */
function sendBitrixRequest($method, $params = []) {
    global $config;
    
    // Получаем и чистим URL вебхука
    $webhookBase = rtrim($config['b24_webhook_url'] ?? '', '/');
    if (empty($webhookBase)) {
        Logger::error('Не настроен b24_webhook_url в config.php');
        return json_encode(['error' => 'CONFIG_MISSING']);
    }
    
    $url = $webhookBase . '/' . ltrim($method, '/');
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // 🔹 Обработка ошибок cURL
    if ($curlError) {
        Logger::error('cURL error', [
            'method' => $method,
            'error' => $curlError,
            'url' => $url
        ]);
        return json_encode(['error' => 'CURL_ERROR', 'message' => $curlError]);
    }
    
    // Обработка HTTP-ошибок
    if ($httpCode !== 200) {
        Logger::error('HTTP error', [
            'method' => $method,
            'http_code' => $httpCode,
            'url' => $url,
            'response_preview' => mb_substr($response, 0, 300)
        ]);
    }
    
    // 🔹 Парсинг ответа
    try {
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (isset($result['error'])) {
            Logger::error('Bitrix REST error', [
                'method' => $method,
                'error' => $result['error'],
                'error_description' => $result['error_description'] ?? null
            ]);
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\JsonException $e) {
        Logger::error('JSON parse error', [
            'method' => $method,
            'error' => $e->getMessage(),
            'response_preview' => mb_substr($response, 0, 500)
        ]);
        return json_encode(['error' => 'JSON_ERROR']);
    }
}

/**
 * Синхронизация банковских счетов для реквизита
 * @param int $requisiteId ID реквизита в Bitrix24
 * @param array $partner1C Данные контрагента из 1С
 * @return array ['synced' => int, 'errors' => int]
 */
function syncBankDetailsToRequisite($requisiteId, $partner1C) {
    $accounts = $partner1C['МассивСчетов'] ?? [];
    $synced = 0;
    $errors = 0;
    
    foreach ($accounts as $account) {
        // 🔹 Пропускаем пустые
        $accountName = trim($account['НаименованиеСчета'] ?? '');
        $accountNum = trim($account['НомерСчета'] ?? '');
        $accountCode = trim($account['Код'] ?? '');
        
        if (empty($accountName) || empty($accountNum)) {
            continue;
        }
        
        // 🔹 Парсим банк: "044525700 АО \"РАЙФФАЙЗЕНБАНК\"" → ['BIK' => '044525700', 'NAME' => 'АО "РАЙФФАЙЗЕНБАНК"']
        $bankParts = parseBankString($account['Банк'] ?? '');
        
        // 🔹 Определяем активность
        $isActive = !isset($account['НедействителенСчет']) || $account['НедействителенСчет'] === 'Нет';
        
        // 🔹 Формируем поля для Bitrix
		$bankFields = [
			'ENTITY_ID' => $requisiteId,
			'COUNTRY_ID' => 1,
			'PRESET_ID' => 5,  // ✅ 5 = банковские реквизиты
			'NAME' => $accountName,
			'XML_ID' => !empty($accountCode) ? '1C_ACC_' . $accountCode : '',
			'ACTIVE' => $isActive ? 'Y' : 'N',
			'RQ_ACC_NUM' => $accountNum,
			'RQ_BIK' => $bankParts['BIK'],
			'RQ_BANK_NAME' => $bankParts['NAME'],
			'RQ_ACC_CURRENCY' => 'RUB',
			'RQ_ACC_NAME' => $partner1C['Наименование'] ?? '',
		];
        
        // 🔍 Ищем существующий счёт по уникальному коду из 1С
        $existing = findBankDetailByXmlId($requisiteId, $accountCode);
        
        if ($existing) {
            // ✅ Обновляем, если есть изменения
            if (isBankDetailChanged($existing, $bankFields)) {
                $result = \Bitrix\Main\Web\Json::decode(
                    sendBitrixRequest('crm.requisite.bankdetail.update', [
                        'id' => $existing['ID'],
                        'fields' => $bankFields
                    ])
                );
                
                if (!empty($result['result']) && $result['result'] === true) {
                    $synced++;
                } else {
                    $errors++;
                    Logger::error('Ошибка обновления банковского счёта', [
                        'account_num' => $accountNum,
                        'error' => $result['error_description'] ?? $result['error'] ?? 'unknown'
                    ]);
                }
            }
        } else {
            // ➕ Создаём новый
            $result = \Bitrix\Main\Web\Json::decode(
                sendBitrixRequest('crm.requisite.bankdetail.add', [
                    'fields' => $bankFields
                ])
            );
            
            if (!empty($result['result']) && is_numeric($result['result'])) {
                $synced++;
                Logger::info('Банковский счёт создан', [
                    'bankdetail_id' => $result['result'],
                    'account_num' => $accountNum,
                    'bank_name' => $bankParts['NAME']
                ]);
            } else {
                $errors++;
                Logger::error('Ошибка создания банковского счёта', [
                    'account_num' => $accountNum,
                    'error' => $result['error_description'] ?? $result['error'] ?? 'unknown'
                ]);
            }
        }
    }
    
    return ['synced' => $synced, 'errors' => $errors];
}

/**
 * Поиск банковского реквизита по XML_ID (код из 1С)
 */
function findBankDetailByXmlId($requisiteId, $code1C) {
    if (empty($code1C)) return null;
    
    $result = \Bitrix\Main\Web\Json::decode(
        sendBitrixRequest('crm.requisite.bankdetail.list', [
            'filter' => [
                'ENTITY_ID' => $requisiteId,
                'XML_ID' => '1C_ACC_' . $code1C
            ],
            'select' => ['ID', 'NAME', 'XML_ID', 'RQ_ACC_NUM', 'RQ_BIK', 'RQ_BANK_NAME', 'ACTIVE', 'RQ_ACC_CURRENCY'],
            'limit' => 1
        ])
    );
    
    return $result['result'][0] ?? null;
}

/**
 * Сравнение полей: изменился ли банковский реквизит
 */
function isBankDetailChanged($existing, $newFields) {
    $fieldsToCompare = [
        'NAME', 'RQ_ACC_NUM', 'RQ_BIK', 'RQ_BANK_NAME', 
        'ACTIVE', 'RQ_ACC_CURRENCY', 'RQ_ACC_NAME'
    ];
    
    foreach ($fieldsToCompare as $field) {
        $old = trim((string)($existing[$field] ?? ''));
        $new = trim((string)($newFields[$field] ?? ''));
        
        if ($old !== $new) {
            return true;
        }
    }
    return false;
}

/**
 * Парсинг строки банка из 1С: "044525700 АО \"РАЙФФАЙЗЕНБАНК\""
 */
function parseBankString($bankStr) {
    $bankStr = trim($bankStr ?? '');
    if (empty($bankStr)) return ['BIK' => '', 'NAME' => ''];
    
    // Удаляем кавычки разных типов
    $bankStr = preg_replace('/[\x{00AB}\x{00BB}"\'`]/u', '', $bankStr);
    
    $bik = '';
    $name = $bankStr;
    
    // Ищем 9-значный БИК
    if (preg_match('/\b(\d{9})\b/', $bankStr, $matches)) {
        $bik = $matches[1];
        // Всё после БИК — название банка
        $parts = explode($bik, $bankStr, 2);
        $name = trim($parts[1] ?? $parts[0] ?? '');
    }
    
    return [
        'BIK' => $bik,
        'NAME' => trim($name, " \t\n\r\0\x0B,;:")
    ];
}

/**
 * Поиск контакта по УИД
 */
function findContactByUid($uid) {
    if (empty($uid)) return false;
    
    $res = \CCrmContact::GetListEx(
        [],
        [
            '=UF_CRM_1774280952' => $uid,
            'CHECK_PERMISSIONS' => 'N'  // ← Важно: отключаем проверку прав
        ],
        false,
        false,
        ['ID', 'NAME', 'LAST_NAME', 'UF_CRM_1774280952']
    );
    
    return $res->Fetch();
}

/**
 * Создание контакта через REST API (использует права вебхука)
 */
function createContact($partner1C) {
    global $config;
    
    $fullName = trim($partner1C['Наименование'] ?? '');
    $nameParts = parseFullName($fullName);
    
    $fields = [
        'NAME' => $nameParts['name'] ?? '',
        'LAST_NAME' => $nameParts['last_name'] ?? '',
        'SECOND_NAME' => $nameParts['middle_name'] ?? '',
        'SOURCE_ID' => '1C',
        'OPENED' => 'Y',
        
        // Пользовательские поля для КОНТАКТОВ
        'UF_CRM_1774280952' => $partner1C['УИД'] ?? '',
        'UF_CRM_1774280985' => ($partner1C['Покупатель'] ?? '') === 'Да' ? 'Да' : 'Нет',
        'UF_CRM_1774281000' => ($partner1C['Поставщик'] ?? '') === 'Да' ? 'Да' : 'Нет',
    ];
    
    // Контактные данные
    if (!empty($partner1C['phone'])) {
        $fields['PHONE'] = [['VALUE' => $partner1C['phone'], 'VALUE_TYPE' => 'MOBILE']];
    }
    if (!empty($partner1C['email'])) {
        $fields['EMAIL'] = [['VALUE' => $partner1C['email'], 'VALUE_TYPE' => 'WORK']];
    }

    // 🔹 Вызов REST API через вебхук
    $result = \Bitrix\Main\Web\Json::decode(
        sendBitrixRequest('crm.contact.add', ['fields' => $fields])
    );
    
    if (!empty($result['result']) && is_numeric($result['result'])) {
        Logger::info('✓ Контакт создан (REST)', [
            'contact_id' => $result['result'],
            'uid' => $fields['UF_CRM_1774280952']
        ]);
        return (int)$result['result'];
    }
    
    Logger::error('❌ crm.contact.add FAILED', [
        'error' => $result['error'] ?? 'unknown',
        'error_description' => $result['error_description'] ?? null,
        'uid' => $partner1C['УИД'] ?? ''
    ]);
    
    return false;
}

/**
 * Обновление контакта через REST API
 */
function updateContact($contactId, $partner1C) {
    global $config;
    
    $fullName = trim($partner1C['Наименование'] ?? '');
    $nameParts = parseFullName($fullName);
    
    $fields = [
        'NAME' => $nameParts['name'] ?? '',
        'LAST_NAME' => $nameParts['last_name'] ?? '',
        'SECOND_NAME' => $nameParts['middle_name'] ?? '',
        
        // Пользовательские поля для КОНТАКТОВ
        'UF_CRM_1774280952' => $partner1C['УИД'] ?? '',
        'UF_CRM_1774280985' => ($partner1C['Покупатель'] ?? '') === 'Да' ? 'Да' : 'Нет',
        'UF_CRM_1774281000' => ($partner1C['Поставщик'] ?? '') === 'Да' ? 'Да' : 'Нет',
    ];
    
    if (!empty($partner1C['phone'])) {
        $fields['PHONE'] = [['VALUE' => $partner1C['phone'], 'VALUE_TYPE' => 'MOBILE']];
    }
    if (!empty($partner1C['email'])) {
        $fields['EMAIL'] = [['VALUE' => $partner1C['email'], 'VALUE_TYPE' => 'WORK']];
    }
    
    $result = \Bitrix\Main\Web\Json::decode(
        sendBitrixRequest('crm.contact.update', [
            'id' => $contactId,
            'fields' => $fields
        ])
    );
    
    if (!empty($result['result']) && $result['result'] === true) {
        return true;
    }

    Logger::error('❌ crm.contact.update FAILED', [
        'contact_id' => $contactId,
        'error' => $result['error'] ?? 'unknown',
        'error_description' => $result['error_description'] ?? null
    ]);
    
    return false;
}

/**
 * Универсальная функция синхронизации контакта
 */
function syncContact($uid, $partner1C) {
    $existing = findContactByUid($uid);
    
    if ($existing) {
        $success = updateContact($existing['ID'], $partner1C);
        return $success 
            ? ['id' => $existing['ID'], 'action' => 'updated'] 
            : ['id' => null, 'action' => null];
    } else {
        $newId = createContact($partner1C);
        return $newId 
            ? ['id' => $newId, 'action' => 'created'] 
            : ['id' => null, 'action' => null];
    }
}

/**
 * Парсинг ФИО из строки 1С
 * Пример: "8-903-660-37-64 Юлия" → ['name'=>'Юлия'], "Иванов Иван Иванович" → [... ]
 */
function parseFullName($str) {
    // Удаляем телефон в любом месте строки
    $str = preg_replace('/\+?[\d\s\-\(\)]{7,}/u', '', $str);
    // Удаляем префиксы
    $str = preg_replace('/^(ИП|Физ\.?\s*лицо)\s*/ui', '', $str);
    $str = trim($str);
    
    $parts = preg_split('/\s+/', $str, 3);
    
    // Эвристика: если 1 часть — скорее всего имя, если 2+ — Фамилия Имя [Отчество]
    if (count($parts) === 1) {
        return ['last_name' => '', 'name' => $parts[0], 'middle_name' => ''];
    }
    
    return [
        'last_name' => $parts[0] ?? '',
        'name' => $parts[1] ?? '',
        'middle_name' => $parts[2] ?? ''
    ];
}