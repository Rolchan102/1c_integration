<?php
/**
 * Шаг 5: Загрузка печатных форм в таймлайн
 * Автоматическая загрузка документов из 1С в таймлайн сделок Битрикс24
 * 
 * Обрабатываемые документы:
 * - Счета на оплату (из /CustomerOrder)
 * - Расходные накладные (из /ExpInvoice)
 * - Приходные накладные (из /purchase)
 * 
 * Направление: 1С → Битрикс24
 * Режим работы: только по cron (каждые 15-30 минут)
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

\Bitrix\Main\Loader::includeModule('crm');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

// Ручная проверка авторизации (без блокировки сессии)
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

// === ЗАГРУЗКА КОНФИГУРАЦИИ ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
$oneC = new OneCClient($config);
Logger::init($config);

// === ПРОВЕРКА ДОСТУПА ===
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

Logger::info('=== ЗАПУСК ШАГА 5: Загрузка документов из 1С в таймлайн сделок ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'timestamp' => date('Y-m-d H:i:s')
]);

// === ПОДКЛЮЧЕНИЕ КЛИЕНТА 1С ===
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/1c_client.php");
$oneC = new OneCClient($config);

// === ОБРАБОТКА ДОКУМЕНТОВ ===
$summary = [
    'invoices' => ['processed' => 0, 'uploaded' => 0, 'errors' => 0],
    'exp_invoices' => ['processed' => 0, 'uploaded' => 0, 'errors' => 0],
    'purchase_docs' => ['processed' => 0, 'uploaded' => 0, 'errors' => 0],
    'upd_docs' => ['processed' => 0, 'uploaded' => 0, 'errors' => 0],
    'total' => 0
];

// Шаг 1: Загрузка счетов на оплату
Logger::info('=== Обработка счетов на оплату ===');
$summary['invoices'] = processInvoices($oneC, $config);

// Шаг 2: Загрузка расходных накладных
Logger::info('=== Обработка расходных накладных ===');
$summary['exp_invoices'] = processExpInvoices($oneC, $config);

// Шаг 3: Загрузка приходных накладных
Logger::info('=== Обработка приходных накладных ===');
$summary['purchase_docs'] = processPurchaseDocs($oneC, $config);

// Шаг 4: УПД
Logger::info('=== Обработка УПД ===');
$summary['upd_docs'] = processUPDDocs($oneC, $config);

// Итоги
$summary['total'] = $summary['invoices']['uploaded'] + 
                    $summary['exp_invoices']['uploaded'] + 
                    $summary['purchase_docs']['uploaded'] +
                    $summary['upd_docs']['uploaded'];

Logger::info('=== ЗАВЕРШЕНИЕ ШАГА 5 ===', [
    'счетов_обработано' => $summary['invoices']['processed'],
    'счетов_загружено' => $summary['invoices']['uploaded'],
    'расходных_накладных_обработано' => $summary['exp_invoices']['processed'],
    'расходных_накладных_загружено' => $summary['exp_invoices']['uploaded'],
    'приходных_накладных_обработано' => $summary['purchase_docs']['processed'],
    'приходных_накладных_загружено' => $summary['purchase_docs']['uploaded'],
    'УПД_обработано' => $summary['upd_docs']['processed'],
    'УПД_загружено' => $summary['upd_docs']['uploaded'],
    'всего_загружено' => $summary['total']
]);

// Вывод результата
$result = [
    'success' => true,
    'summary' => $summary,
    'timestamp' => date('Y-m-d H:i:s')
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * Обработка счетов на оплату
 * 
 * @param OneCClient $oneC Клиент 1С
 * @param array $config Конфигурация
 * @return array Статистика обработки
 */
function processInvoices($oneC, $config) {
    $processed = 0;
    $uploaded = 0;
    $errors = 0;
    
    try {
        // Получаем все счета из 1С
        $invoices = getAllInvoicesFrom1C($config);

        if (empty($invoices)) {
            Logger::debug('Счета не найдены в 1С');
            return ['processed' => 0, 'uploaded' => 0, 'errors' => 0];
        }
        
        Logger::info('Получено счетов из 1С', ['count' => count($invoices)]);
        
        foreach ($invoices as $invoice) {
            $processed++;
            
            try {
                // Проверяем наличие обязательных полей
                if (empty($invoice['DNK_id']) || empty($invoice['ИДЗаписи'])) {
                    continue;
                }

                $dealId = $invoice['DNK_id'];
                $invoiceUid = $invoice['ИДЗаписи'];
                $invoiceNumber = $invoice['Номер'] ?? '';
                $invoiceDate = $invoice['Дата'] ?? '';
                
                // Проверяем, существует ли сделка в Битрикс24
                $deal = \CCrmDeal::GetByID($dealId);
                if (!$deal) {
                    continue;
                }
                
                // Проверяем, не загружен ли уже этот счет
				$invoiceUid = $invoice['ИДЗаписи'] ?? '';
				if (checkAndMarkDocumentUploaded($dealId, $invoiceUid, 'invoice', $config)) {
					continue;
				}
                
                // Скачиваем PDF счета
                $pdfContent = downloadInvoicePdf($invoiceUid, $dealId, $config);
                if (!$pdfContent) {
                    Logger::warning('Не удалось скачать PDF счета', [
                        'deal_id' => $dealId,
                        'номер_счета' => $invoiceNumber
                    ]);
                    $errors++;
                    continue;
                }
                
                // Загружаем файл в таймлайн
                $fileId = uploadFileToTimeline($dealId, $pdfContent, 'Счет_' . $invoiceNumber . '.pdf', 'Счет на оплату №' . $invoiceNumber, $config);
                
                if ($fileId) {
                    $uploaded++;
                    
                    // Обновляем поля сделки
                    $updateFields = [
                        $config['deal_fields']['UF_CRM_INVOICE_NUMBER'] => $invoiceNumber,
                        $config['deal_fields']['UF_CRM_INVOICE_DATE'] => formatDate1C($invoiceDate)
                    ];
                    
                    updateDealViaRest($dealId, $updateFields, $config);
                    
                    Logger::info('Счет успешно загружен в таймлайн', [
                        'deal_id' => $dealId,
                        'номер_счета' => $invoiceNumber,
                        'file_id' => $fileId
                    ]);
                } else {
                    $errors++;
                    Logger::error('Ошибка загрузки счета в таймлайн', [
                        'deal_id' => $dealId,
                        'номер_счета' => $invoiceNumber
                    ]);
                }
                
                // Пауза между запросами
                usleep(100000);
                
            } catch (\Exception $e) {
                $errors++;
                Logger::error('Ошибка обработки счета', [
                    'номер_счета' => $invoice['Номер'] ?? 'неизвестен',
                    'ошибка' => $e->getMessage()
                ]);
            }
        }
        
    } catch (\Exception $e) {
        Logger::error('Критическая ошибка при обработке счетов', [
            'ошибка' => $e->getMessage()
        ]);
        $errors++;
    }
    
    return [
        'processed' => $processed,
        'uploaded' => $uploaded,
        'errors' => $errors
    ];
}

/**
 * Обработка расходных накладных
 * 
 * @param OneCClient $oneC Клиент 1С
 * @param array $config Конфигурация
 * @return array Статистика обработки
 */
function processExpInvoices($oneC, $config) {
    $processed = 0;
    $uploaded = 0;
    $errors = 0;
    
    try {
        // Получаем расходные накладные из 1С
        $expInvoices = getExpInvoicesFrom1C($config);
        
        if (empty($expInvoices)) {
            Logger::debug('Расходные накладные не найдены в 1С');
            return ['processed' => 0, 'uploaded' => 0, 'errors' => 0];
        }
        
        Logger::info('Получено расходных накладных из 1С', ['count' => count($expInvoices)]);
        
        foreach ($expInvoices as $expInvoice) {
            $processed++;

            try {
                $dealId = $expInvoice['DNK_id'];
                $expInvoiceUid = $expInvoice['ИДЗаписи'];
                $expInvoiceNumber = $expInvoice['Номер'] ?? '';
                $expInvoiceDate = $expInvoice['Дата'] ?? '';
                
                // Проверяем, существует ли сделка
                $deal = \CCrmDeal::GetByID($dealId);

                // Проверяем, не загружена ли уже эта накладная
				$expInvoiceUid = $expInvoice['ИДЗаписи'] ?? '';
				if (checkAndMarkDocumentUploaded($dealId, $expInvoiceUid, 'expense', $config)) {
					Logger::debug('Пропуск расходной: уже загружена (по УИД)', [
						'deal_id' => $dealId,
						'expense_uid' => $expInvoiceUid
					]);
					continue;
				}
                
                // Скачиваем PDF расходной накладной (если доступен)
                // Примечание: 1С может не предоставлять прямую ссылку на PDF расходной накладной
                // В этом случае можно создать запись в таймлайне без файла
				$pdfContent = downloadExpInvoicePdf($expInvoiceUid, $dealId, $config);

                // Создаем запись в таймлайне
                $comment = 'Расходная накладная №' . $expInvoiceNumber . ' от ' . formatDate1C($expInvoiceDate);
                
                if ($pdfContent) {
                    $fileId = uploadFileToTimeline($dealId, $pdfContent, 'Расходная_' . $expInvoiceNumber . '.pdf', $comment, $config);
                } else {
                    // Создаем запись без файла
                    $fileId = createTimelineEntry($dealId, $comment, $config);
                }

                if ($fileId) {
                    $uploaded++;

                    // Обновляем поля сделки
                    $updateFields = [
                        $config['deal_fields']['UF_CRM_EXPENSE_NUMBER'] => $expInvoiceNumber,
                        $config['deal_fields']['UF_CRM_EXPENSE_DATE'] => formatDate1C($expInvoiceDate)
                    ];
                    
                    updateDealViaRest($dealId, $updateFields, $config);
                    
                    Logger::info('Расходная накладная добавлена в таймлайн', [
                        'deal_id' => $dealId,
                        'номер' => $expInvoiceNumber,
                        'file_id' => $fileId
                    ]);
                } else {
                    $errors++;
                }
                
                // Пауза между запросами
                usleep(100000);
                
            } catch (\Exception $e) {
                $errors++;
                Logger::error('Ошибка обработки расходной накладной', [
                    'номер' => $expInvoice['Номер'] ?? 'неизвестен',
                    'ошибка' => $e->getMessage()
                ]);
            }
        }
        
    } catch (\Exception $e) {
        Logger::error('Критическая ошибка при обработке расходных накладных', [
            'ошибка' => $e->getMessage()
        ]);
        $errors++;
    }
    
    return [
        'processed' => $processed,
        'uploaded' => $uploaded,
        'errors' => $errors
    ];
}

/**
 * Обработка приходных накладных
 * 
 * @param OneCClient $oneC Клиент 1С
 * @param array $config Конфигурация
 * @return array Статистика обработки
 */
function processPurchaseDocs($oneC, $config) {
    $processed = 0;
    $uploaded = 0;
    $errors = 0;
    
    try {
        // Получаем приходные накладные из 1С
        $purchaseDocs = getPurchaseDocsFrom1C($config);
        
        if (empty($purchaseDocs)) {
            Logger::debug('Приходные накладные не найдены в 1С');
            return ['processed' => 0, 'uploaded' => 0, 'errors' => 0];
        }
        
        Logger::info('Получено приходных накладных из 1С', ['count' => count($purchaseDocs)]);
        
        foreach ($purchaseDocs as $purchaseDoc) {
            $processed++;
            
            try {
                // Проверяем наличие обязательных полей
                if (empty($purchaseDoc['ИДЗаписи'])) {
                    Logger::debug('Пропуск приходной накладной: отсутствуют обязательные поля', [
                        'номер' => $purchaseDoc['Номер'] ?? 'неизвестен'
                    ]);
                    continue;
                }
                
                $purchaseDocUid = $purchaseDoc['ИДЗаписи'];
                $purchaseDocNumber = $purchaseDoc['Номер'] ?? '';
                $purchaseDocDate = $purchaseDoc['Дата'] ?? '';
                
                // Пытаемся найти сделку по заказу поставщика
                $dealId = findDealBySupplierOrder($purchaseDoc['ЗаказПоставщику'] ?? '');
                
                if (!$dealId) {
                    continue;
                }
                
                // Проверяем, не загружена ли уже эта накладная
				if (checkAndMarkDocumentUploaded($dealId, $purchaseDocUid, 'purchase', $config)) {
					Logger::debug('Пропуск приходной: уже загружена (по УИД)', [
						'deal_id' => $dealId,
						'purchase_uid' => $purchaseDocUid
					]);
					continue;
				}
                
                // Создаем запись в таймлайне
                $comment = 'Приходная накладная №' . $purchaseDocNumber . ' от ' . formatDate1C($purchaseDocDate);
                $fileId = createTimelineEntry($dealId, $comment, $config);
                
                if ($fileId) {
                    $uploaded++;
                    
                    Logger::info('Приходная накладная добавлена в таймлайн', [
                        'deal_id' => $dealId,
                        'номер' => $purchaseDocNumber,
                        'file_id' => $fileId
                    ]);
                } else {
                    $errors++;
                    Logger::error('Ошибка добавления приходной накладной в таймлайн', [
                        'deal_id' => $dealId,
                        'номер' => $purchaseDocNumber
                    ]);
                }
                
                // Пауза между запросами
                usleep(100000);
                
            } catch (\Exception $e) {
                $errors++;
                Logger::error('Ошибка обработки приходной накладной', [
                    'номер' => $purchaseDoc['Номер'] ?? 'неизвестен',
                    'ошибка' => $e->getMessage()
                ]);
            }
        }
        
    } catch (\Exception $e) {
        Logger::error('Критическая ошибка при обработке приходных накладных', [
            'ошибка' => $e->getMessage()
        ]);
        $errors++;
    }
    
    return [
        'processed' => $processed,
        'uploaded' => $uploaded,
        'errors' => $errors
    ];
}

/**
 * Получение всех счетов из 1С
 * 
 * @return array|false Массив счетов или false при ошибке
 */
function getAllInvoicesFrom1C($config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        // Установка заголовка авторизации
        $authHeader = 'Basic ' . base64_encode(
			$config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
		);
        $httpClient->setHeader('Authorization', $authHeader);
        
        // Запрос всех счетов
        $url = rtrim($config['1c_base_url'], '/') . '/orders/0/0';
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() !== 200) {
            Logger::error('Ошибка HTTP при получении счетов', [
                'status' => $httpClient->getStatus(),
                'error' => $httpClient->getError()
            ]);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Ошибка парсинга JSON счетов', [
                'ошибка' => json_last_error_msg()
            ]);
            return false;
        }
        
        return is_array($data) ? $data : [];
        
    } catch (\Exception $e) {
        Logger::error('Исключение при получении счетов из 1С', [
            'ошибка' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение расходных накладных из 1С
 * 
 * @return array|false Массив расходных накладных или false при ошибке
 */
function getExpInvoicesFrom1C($config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
			$config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
		);
        $httpClient->setHeader('Authorization', $authHeader);
        
        $url = rtrim($config['1c_base_url'], '/') . '/ExpInvoice';
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() !== 200) {
            Logger::error('Ошибка HTTP при получении расходных накладных', [
                'status' => $httpClient->getStatus(),
                'error' => $httpClient->getError()
            ]);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Ошибка парсинга JSON расходных накладных', [
                'ошибка' => json_last_error_msg()
            ]);
            return false;
        }
        
        return is_array($data) ? $data : [];
        
    } catch (\Exception $e) {
        Logger::error('Исключение при получении расходных накладных из 1С', [
            'ошибка' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Получение приходных накладных из 1С
 * 
 * @return array|false Массив приходных накладных или false при ошибке
 */
function getPurchaseDocsFrom1C($config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);

        $authHeader = 'Basic ' . base64_encode(
			$config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
		);
        $httpClient->setHeader('Authorization', $authHeader);

        $url = rtrim($config['1c_base_url'], '/') . '/purchase';
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() !== 200) {
            Logger::error('Ошибка HTTP при получении приходных накладных', [
                'status' => $httpClient->getStatus(),
                'error' => $httpClient->getError()
            ]);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Ошибка парсинга JSON приходных накладных', [
                'ошибка' => json_last_error_msg()
            ]);
            return false;
        }
        
        return is_array($data) ? $data : [];
        
    } catch (\Exception $e) {
        Logger::error('Исключение при получении приходных накладных из 1С', [
            'ошибка' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Скачивание PDF счета из 1С
 * 
 * @param string $invoiceUid УИД счета
 * @param string $dnkId ID сделки (используется в эндпоинте)
 * @param array $config Конфигурация
 * @return string|false Содержимое PDF или false при ошибке
 */
function downloadInvoicePdf($invoiceUid, $dnkId, $config) {
    try {
        // 🔹 Формат эндпоинта: /orders/pf/{DNK_id}
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 60,
            'streamTimeout' => 60
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding(
                ($config['1c_auth']['login'] ?? '') . ':' . ($config['1c_auth']['password'] ?? ''),
                'UTF-8',
                'UTF-8'
            )
        );
        $httpClient->setHeader('Authorization', $authHeader);
        
        $url = rtrim($config['1c_base_url'], '/') . '/orders/pf/' . urlencode($dnkId);
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() === 200) {
            // 🔧 ИСПРАВЛЕНИЕ: используем ->get() для доступа к заголовкам
            $headers = $httpClient->getHeaders();
            $contentType = $headers->get('Content-Type') 
                        ?? $headers->get('content-type') 
                        ?? $headers->get('Content-Type') // регистронезависимый поиск
                        ?? '';
            
            // Проверяем, что это действительно PDF
            if (stripos($contentType, 'application/pdf') !== false || 
                stripos($contentType, 'octet-stream') !== false ||
                stripos($contentType, 'application/octet-stream') !== false) {
                
                \Integration\Logger::debug('PDF счета успешно получен', [
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
            
            // Если Content-Type не PDF, но ответ не пустой — возможно, это всё равно файл
            // Логируем и возвращаем, если размер ответа разумный (>100 байт)
            if (!empty($response) && strlen($response) > 100) {
                \Integration\Logger::warning('PDF получен с неожиданным Content-Type', [
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
        }
        
        \Integration\Logger::error('Ошибка скачивания PDF счета', [
            'dnk_id' => $dnkId,
            'url' => $url,
            'status' => $httpClient->getStatus(),
            'content_type' => $contentType ?? 'unknown',
            'error' => $httpClient->getError(),
            'response_preview' => mb_substr($response, 0, 200)
        ]);
        
        return false;
        
    } catch (\Exception $e) {
        \Integration\Logger::error('Исключение при скачивании PDF счета', [
            'dnk_id' => $dnkId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

/**
 * Загрузка файла в таймлайн сделки
 * 
 * @param int $dealId ID сделки
 * @param string $fileContent Содержимое файла
 * @param string $fileName Имя файла
 * @param string $comment Комментарий к файлу
 * @return int|false ID файла или false при ошибке
 */
function uploadFileToTimeline($dealId, $fileContent, $fileName, $comment, $config) {
    try {
        // Попробовать через REST API (надёжнее)
        $base64 = base64_encode($fileContent);
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment,
                'FILES' => [[$fileName, $base64]]
            ]
        ];
        
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.add.json';
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && !empty($result['result'])) {
            \Integration\Logger::debug('Файл загружен в таймлайн через REST', [
                'deal_id' => $dealId,
                'timeline_id' => $result['result']
            ]);
            return $result['result'];
        }

    } catch (\Exception $e) {
        \Integration\Logger::error('Ошибка загрузки файла в таймлайн', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Создание записи в таймлайне без файла
 * 
 * @param int $dealId ID сделки
 * @param string $comment Комментарий
 * @return int|false ID записи или false при ошибке
 */
function createTimelineEntry($dealId, $comment, $config) {  // ← добавили $config
    try {
        // Попробовать через REST API
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment
            ]
        ];
        
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.add.json';
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && !empty($result['result'])) {
            \Integration\Logger::debug('Запись добавлена в таймлайн через REST', [
                'deal_id' => $dealId,
                'timeline_id' => $result['result']
            ]);
            return $result['result'];
        }

    } catch (\Exception $e) {
        \Integration\Logger::error('Ошибка создания записи в таймлайне', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Проверка, загружен ли уже документ в таймлайн сделки
 * 
 * @param int $dealId ID сделки
 * @param string $documentNumber Номер документа
 * @param string $documentType Тип документа (Счет, Расходная, Приходная)
 * @param array $config Конфигурация интеграции
 * @return bool true если документ уже загружен
 */
function isDocumentAlreadyUploaded($dealId, $documentNumber, $documentType, $config) {
    try {
        // 🔹 Используем REST API: crm.timeline.comment.list
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.timeline.comment.list.json';
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'filter' => [
                    'ENTITY_TYPE' => 'deal',
                    'ENTITY_ID' => $dealId,
                    // Ищем по комментарию, содержащему номер документа
                    '>=COMMENT' => $documentType . ' №' . $documentNumber,
                ],
                'select' => ['ID', 'COMMENT'],
                'order' => ['ID' => 'DESC'],
                'limit' => 5  // Проверяем последние 5 записей
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode !== 200) {
            // При ошибке API считаем, что документа нет (чтобы не блокировать загрузку)
            \Integration\Logger::debug('Ошибка проверки таймлайна', [
                'deal_id' => $dealId,
                'http_code' => $httpCode
            ]);
            return false;
        }
        
        $result = json_decode($response, true);
        
        if (empty($result['result'])) {
            return false; // Записей не найдено
        }
        
        // 🔍 Ищем точное совпадение по номеру документа в комментариях
        $pattern = '/' . preg_quote($documentType, '/') . '\s*№?\s*' . preg_quote($documentNumber, '/') . '/i';
        
        foreach ($result['result'] as $entry) {
            $comment = $entry['COMMENT'] ?? '';
            if (preg_match($pattern, $comment)) {
                \Integration\Logger::debug('Документ уже в таймлайне', [
                    'deal_id' => $dealId,
                    'document' => $documentType . ' №' . $documentNumber,
                    'timeline_id' => $entry['ID']
                ]);
                return true;
            }
        }
        
        return false;
        
    } catch (\Exception $e) {
        // Ошибка не критична — считаем, что документа нет
        \Integration\Logger::debug('Исключение при проверке таймлайна', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Поиск сделки по заказу поставщика
 * 
 * @param string $supplierOrderNumber Номер заказа поставщика
 * @return int|false ID сделки или false
 */
function findDealBySupplierOrder($supplierOrderNumber) {
    if (empty($supplierOrderNumber)) {
        return false;
    }
    
    try {
        // Ищем сделку по полю заказа поставщика
        // @todo: реализовать при наличии соответствующего поля
        
        // Временная заглушка: возвращаем false
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Ошибка поиска сделки по заказу поставщика', [
            'номер' => $supplierOrderNumber,
            'ошибка' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Форматирование даты из формата 1С в формат Битрикс24
 * 
 * @param string $date Дата в формате "07.02.2026 0:00:00"
 * @return string Дата в формате "07.02.2026"
 */
function formatDate1C($date) {
    if (empty($date)) {
        return '';
    }
    
    // Удаляем время из даты
    $date = preg_replace('/\s.*$/', '', $date);
    
    return $date;
}

/**
 * Обновление полей сделки через REST API
 * @param int $dealId ID сделки
 * @param array $fields Поля для обновления
 * @param array $config Конфигурация
 * @return bool true при успехе
 */
function updateDealViaRest($dealId, $fields, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.update.json';
        
        $postData = http_build_query([
            'id' => (int)$dealId,
            'fields' => $fields
        ]);
        
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);
        
        return ($httpCode === 200 && !empty($result['result']));
        
    } catch (\Exception $e) {
        \Integration\Logger::error('Ошибка обновления сделки через REST', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Проверка и отметка документа как загруженного (по УИД)
 * 
 * @param int $dealId ID сделки
 * @param string $docUid УИД документа из 1С
 * @param string $docType Код типа документа: 'invoice', 'expense', 'purchase', 'shipping'
 * @param array $config Конфигурация
 * @return bool true если документ УЖЕ был загружен (дубликат)
 */
function checkAndMarkDocumentUploaded($dealId, $docUid, $docType, $config) {
    // Проверяем, что тип документа известен
    $fieldCode = $config['doc_uid_fields'][$docType] ?? null;
    if (!$fieldCode || empty($docUid)) {
        return false; // Неизвестный тип или пустой УИД — пропускаем проверку
    }
    
    try {
        // 1. Получаем текущие данные сделки через REST
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.get.json';
        
        $curl = curl_init($webhookUrl . '?id=' . (int)$dealId);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $result = json_decode($response, true);
        $existingUids = $result['result'][$fieldCode] ?? '';
        
        // 2. Парсим список УИД (формат хранения: "uid1,uid2,uid3")
        $uids = array_filter(array_map('trim', explode(',', $existingUids)));
        
        // 3. Если УИД уже есть — это дубликат
        if (in_array($docUid, $uids, true)) {
            \Integration\Logger::debug('Документ уже отмечен как загруженный', [
                'deal_id' => $dealId,
                'doc_type' => $docType,
                'doc_uid' => $docUid,
                'field' => $fieldCode
            ]);
            return true;
        }
        
        // 4. Добавляем новый УИД и обновляем поле
        $uids[] = $docUid;
        $newValue = implode(',', array_unique($uids));
        
        $updateUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.update.json';
        $updateCurl = curl_init($updateUrl);
        curl_setopt_array($updateCurl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'id' => (int)$dealId,
                'fields' => [$fieldCode => $newValue]
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $updateResponse = curl_exec($updateCurl);
        $updateHttpCode = curl_getinfo($updateCurl, CURLINFO_HTTP_CODE);
        curl_close($updateCurl);
        
        if ($updateHttpCode === 200) {
            \Integration\Logger::debug('УИД документа сохранён в сделке', [
                'deal_id' => $dealId,
                'doc_type' => $docType,
                'doc_uid' => $docUid,
                'field' => $fieldCode
            ]);
        } else {
        }
        
        return false; // Документ не был загружен ранее
        
    } catch (\Exception $e) {
        \Integration\Logger::error('Исключение при проверке дубликатов документов', [
            'deal_id' => $dealId,
            'doc_uid' => $docUid,
            'error' => $e->getMessage()
        ]);
        return false; // При ошибке считаем, что дубля нет (чтобы не блокировать загрузку)
    }
}

/**
 * Получение списка УПД из 1С
 * 
 * @param array $config Конфигурация
 * @return array|false Массив УПД или false при ошибке
 */
function getUPDDocsFrom1C($config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
            $config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/json');
        
        // 🔹 Эндпоинт для получения списка УПД (адаптируйте под вашу 1С)
        $url = rtrim($config['1c_base_url'], '/') . '/orders/upd_list/0/0';
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() !== 200) {
            Logger::error('Ошибка HTTP при получении списка УПД', [
                'url' => $url,
                'status' => $httpClient->getStatus(),
                'error' => $httpClient->getError()
            ]);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Ошибка парсинга JSON списка УПД', [
                'error' => json_last_error_msg(),
                'preview' => mb_substr($response, 0, 300)
            ]);
            return false;
        }
        
        return is_array($data) ? $data : [];
        
    } catch (\Exception $e) {
        Logger::error('Исключение при получении списка УПД из 1С', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Скачивание PDF УПД из 1С
 * 
 * @param string $updUid УИД УПД (для логирования)
 * @param string $dnkId ID сделки (используется в эндпоинте)
 * @param array $config Конфигурация
 * @return string|false Содержимое PDF или false при ошибке
 */
function downloadUPDPdf($updUid, $dnkId, $config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 60,
            'streamTimeout' => 60
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding(
                ($config['1c_auth']['login'] ?? '') . ':' . ($config['1c_auth']['password'] ?? ''),
                'UTF-8',
                'UTF-8'
            )
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/pdf');
        
        // 🔹 Эндпоинт: /orders/upd/{DNK_id}
        $url = rtrim($config['1c_base_url'], '/') . '/orders/upd/' . urlencode($dnkId);
        
        Logger::debug('Запрос УПД из 1С', ['url' => $url, 'dnk_id' => $dnkId]);
        
        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() === 200) {
            $headers = $httpClient->getHeaders();
            $contentType = $headers->get('Content-Type') 
                        ?? $headers->get('content-type') 
                        ?? '';
            
            // Проверяем, что это PDF или бинарный файл
            if (stripos($contentType, 'application/pdf') !== false || 
                stripos($contentType, 'octet-stream') !== false ||
                stripos($contentType, 'application/octet-stream') !== false ||
                stripos($response, '%PDF-') === 0) { // Магические байты PDF
                
                Logger::debug('УПД успешно получен', [
                    'upd_uid' => $updUid,
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
            
            // Если ответ не пустой, но Content-Type странный — логируем и пробуем вернуть
            if (!empty($response) && strlen($response) > 100) {
                Logger::warning('УПД получен с неожиданным Content-Type', [
                    'upd_uid' => $updUid,
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
        }
        
        Logger::error('Ошибка скачивания УПД', [
            'upd_uid' => $updUid,
            'dnk_id' => $dnkId,
            'url' => $url,
            'status' => $httpClient->getStatus(),
            'content_type' => $contentType ?? 'unknown',
            'error' => $httpClient->getError(),
            'response_preview' => mb_substr($response, 0, 200)
        ]);
        
        return false;
        
    } catch (\Exception $e) {
        Logger::error('Исключение при скачивании УПД', [
            'upd_uid' => $updUid,
            'dnk_id' => $dnkId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Обработка УПД (Универсальный передаточный документ)
 * 
 * @param OneCClient $oneC Клиент 1С
 * @param array $config Конфигурация
 * @return array Статистика обработки
 */
function processUPDDocs($oneC, $config) {
    $processed = 0;
    $uploaded = 0;
    $errors = 0;
    
    try {
        // Получаем список УПД из 1С
        $updDocs = getUPDDocsFrom1C($config);
        
        if ($updDocs === false) {
            Logger::error('Не удалось получить список УПД из 1С');
            return ['processed' => 0, 'uploaded' => 0, 'errors' => 0];
        }
        
        if (empty($updDocs)) {
            Logger::debug('УПД не найдены в 1С');
            return ['processed' => 0, 'uploaded' => 0, 'errors' => 0];
        }
        
        Logger::info('Получено УПД из 1С', ['count' => count($updDocs)]);
        
        foreach ($updDocs as $upd) {
            $processed++;
            
            try {
                // Проверяем обязательные поля
                $dnkId = $upd['DNK_id'] ?? '';
                $updUid = $upd['ИДЗаписи'] ?? '';
                $updNumber = $upd['Номер'] ?? '';
                $updDate = $upd['Дата'] ?? '';
                
                if (empty($dnkId) || empty($updUid)) {
                    Logger::debug('Пропуск УПД: отсутствуют обязательные поля', [
                        'номер' => $updNumber,
                        'dnk_id' => $dnkId,
                        'upd_uid' => $updUid
                    ]);
                    continue;
                }

                // Проверяем, существует ли сделка
                $deal = \CCrmDeal::GetByID($dnkId);
                if (!$deal) {
                    Logger::debug('Пропуск УПД: сделка не найдена', [
                        'deal_id' => $dnkId,
                        'номер_УПД' => $updNumber
                    ]);
                    continue;
                }
                
                // Проверяем, не загружен ли уже этот УПД (по УИД)
                if (checkAndMarkDocumentUploaded($dnkId, $updUid, 'upd', $config)) {
                    Logger::debug('Пропуск УПД: уже загружен (по УИД)', [
                        'deal_id' => $dnkId,
                        'upd_uid' => $updUid
                    ]);
                    continue;
                }
                
                // Скачиваем PDF УПД
                $pdfContent = downloadUPDPdf($updUid, $dnkId, $config);
                if (!$pdfContent) {
                    Logger::warning('Не удалось скачать PDF УПД', [
                        'deal_id' => $dnkId,
                        'номер_УПД' => $updNumber
                    ]);
                    $errors++;
                    continue;
                }
                
                // Загружаем файл в таймлайн
                $fileName = 'УПД_' . ($updNumber ?: $updUid) . '.pdf';
                $comment = 'УПД №' . $updNumber . ' от ' . formatDate1C($updDate);
                
                $fileId = uploadFileToTimeline($dnkId, $pdfContent, $fileName, $comment, $config);
                
                if ($fileId) {
                    $uploaded++;
                    
                    // Обновляем поля сделки (номер и дата УПД)
                    $updateFields = [];
                    if (!empty($config['deal_fields']['UF_CRM_UPD_NUMBER'])) {
                        $updateFields[$config['deal_fields']['UF_CRM_UPD_NUMBER']] = $updNumber;
                    }
                    if (!empty($config['deal_fields']['UF_CRM_UPD_DATE'])) {
                        $updateFields[$config['deal_fields']['UF_CRM_UPD_DATE']] = formatDate1C($updDate);
                    }
                    
                    if (!empty($updateFields)) {
                        updateDealViaRest($dnkId, $updateFields, $config);
                    }
                    
                    Logger::info('УПД успешно загружен в таймлайн', [
                        'deal_id' => $dnkId,
                        'номер_УПД' => $updNumber,
                        'file_id' => $fileId
                    ]);
                } else {
                    $errors++;
                    Logger::error('Ошибка загрузки УПД в таймлайн', [
                        'deal_id' => $dnkId,
                        'номер_УПД' => $updNumber
                    ]);
                }
                
                // Пауза между запросами (100мс)
                usleep(100000);
                
            } catch (\Exception $e) {
                $errors++;
                Logger::error('Ошибка обработки УПД', [
                    'номер' => $upd['Номер'] ?? 'неизвестен',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
    } catch (\Exception $e) {
        Logger::error('Критическая ошибка при обработке УПД', [
            'error' => $e->getMessage()
        ]);
        $errors++;
    }
    
    return [
        'processed' => $processed,
        'uploaded' => $uploaded,
        'errors' => $errors
    ];
}

/**
 * Скачивание PDF Расходной накладной (Торг-12) из 1С
 * 
 * @param string $expUid УИД расходной накладной (для логирования)
 * @param string $dnkId ID сделки (используется в эндпоинте)
 * @param array $config Конфигурация
 * @return string|false Содержимое PDF или false при ошибке
 */
function downloadExpInvoicePdf($expUid, $dnkId, $config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 60,
            'streamTimeout' => 60
        ]);
        
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding(
                ($config['1c_auth']['login'] ?? '') . ':' . ($config['1c_auth']['password'] ?? ''),
                'UTF-8',
                'UTF-8'
            )
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/pdf');
        
        // Эндпоинт: /orders/torg12/{DNK_id}
        $url = rtrim($config['1c_base_url'], '/') . '/orders/torg12/' . urlencode($dnkId);

        $response = $httpClient->get($url);
        
        if ($httpClient->getStatus() === 200) {
            $headers = $httpClient->getHeaders();
            $contentType = $headers->get('Content-Type') 
                        ?? $headers->get('content-type') 
                        ?? '';
            
            // Проверяем, что это PDF или бинарный файл
            $isPdf = (
                stripos($contentType, 'application/pdf') !== false || 
                stripos($contentType, 'octet-stream') !== false ||
                stripos($contentType, 'application/octet-stream') !== false ||
                stripos($response, '%PDF-') === 0 // Магические байты PDF
            );
            
            if ($isPdf) {
                Logger::debug('Расходная накладная успешно получена', [
                    'exp_uid' => $expUid,
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
            
            // Если ответ не пустой, но Content-Type странный — логируем и пробуем вернуть
            if (!empty($response) && strlen($response) > 100) {
                Logger::warning('Расходная накладная получена с неожиданным Content-Type', [
                    'exp_uid' => $expUid,
                    'dnk_id' => $dnkId,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($response)
                ]);
                return $response;
            }
        }

        return false;
        
    } catch (\Exception $e) {
        return false;
    }
}
?>