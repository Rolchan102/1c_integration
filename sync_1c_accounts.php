<?php
/**
 * Синхронизация счетов и касс из 1С в списки Битрикс24
 * Запускать через агент или cron: php /path/to/sync_1c_accounts.php
 * 
 * Архитектура: как в step6_sync_products.php — используем методы 1c_client.php
 */

// ФИКС ДЛЯ CLI: определяем DOCUMENT_ROOT ДО любого require
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('crm');

$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

Logger::init($config);
$oneC = new OneCClient($config);

set_time_limit(300);

Logger::info('=== НАЧАЛО СИНХРОНИЗАЦИИ СПРАВОЧНИКОВ ===');

// === НАСТРОЙКИ ===
$syncConfig = [
    'bank' => [
        'iblock_id' => 57,
        'method' => 'getBankAccounts',
        'props' => [
            'uid' => 1090,
            'owner' => 1091,
        ]
    ],
    'cash' => [
        'iblock_id' => 58,
        'method' => 'getCashboxes',
        'props' => [
            'uid' => 1092,
        ]
    ],
    // Организации
    'organization' => [
        'iblock_id' => 59,
        'method' => 'getOrganizations',  // ← Метод из 1c_client.php
        'props' => [
            'uid' => 1096,
            'inn' => 1093,
            'kpp' => 1094,
            'code' => 1095,
        ]
    ]
];

/**
 * Поиск элемента инфоблока по свойству УИД
 */
function findElementByUid($iblockId, $propertyId, $uid) {
    $res = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'PROPERTY_' . $propertyId => $uid],
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME']
    );
    return $res->Fetch();
}

/**
 * Обновление или создание элемента
 */
function syncElement($type, $item, $config, $syncConfig) {
    $iblockId = $syncConfig[$type]['iblock_id'];
    $uidProp = $syncConfig[$type]['props']['uid'];
    
    $uid = $item['УИД'] ?? '';
    $name = $item['Наименование'] ?? '';
    
    if (empty($uid)) {
        Logger::warning('Пропущен элемент без УИД', ['type' => $type, 'name' => $name]);
        return false;
    }
    
    $existing = findElementByUid($iblockId, $uidProp, $uid);
    
    $fields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => $name,
        'ACTIVE' => 'Y',
        'PROPERTY_VALUES' => [$uidProp => $uid]
    ];
    
    // Обработка дополнительных полей для организаций
    if ($type === 'organization') {
        $props = $syncConfig[$type]['props'];
        
        if (!empty($item['ИНН']) && isset($props['inn'])) {
            $fields['PROPERTY_VALUES'][$props['inn']] = $item['ИНН'];
        }
        if (!empty($item['КПП']) && isset($props['kpp'])) {
            $fields['PROPERTY_VALUES'][$props['kpp']] = $item['КПП'];
        }
        if (!empty($item['Код']) && isset($props['code'])) {
            $fields['PROPERTY_VALUES'][$props['code']] = $item['Код'];
        }
    }
    
    // Для банков: владелец
    if ($type === 'bank' && !empty($item['Владелец']) && isset($syncConfig[$type]['props']['owner'])) {
        $fields['PROPERTY_VALUES'][$syncConfig[$type]['props']['owner']] = $item['Владелец'];
    }
    
    $el = new \CIBlockElement;
    
    if ($existing) {
        // Обновление
        $result = $el->Update($existing['ID'], $fields);
        if ($result) {
            Logger::debug('Элемент обновлён', [
                'type' => $type,
                'id' => $existing['ID'],
                'uid' => $uid
            ]);
            return true;
        } else {
            Logger::error('Ошибка обновления', [
                'type' => $type,
                'id' => $existing['ID'],
                'error' => $el->LAST_ERROR
            ]);
            return false;
        }
    } else {
        // Создание
        $newId = $el->Add($fields);
        if ($newId) {
            Logger::info('Элемент создан', [
                'type' => $type,
                'id' => $newId,
                'uid' => $uid,
                'name' => $name
            ]);
            return true;
        } else {
            Logger::error('Ошибка создания', [
                'type' => $type,
                'uid' => $uid,
                'error' => $el->LAST_ERROR
            ]);
            return false;
        }
    }
}

/**
 * Основная функция синхронизации одного типа
 */
function runSync($type, $oneC, $config, $syncConfig) {
    Logger::info('=== Начало синхронизации ===', ['type' => $type]);
    
    $iblockId = $syncConfig[$type]['iblock_id'];
    $method = $syncConfig[$type]['method'];
    
    // Вызываем метод из 1c_client.php — та же аутентификация, что в step6!
    $data = $oneC->$method();
    
    if ($data === false) {
        Logger::error('Не удалось получить данные из 1С', ['type' => $type, 'method' => $method]);
        return false;
    }
    
    Logger::info('Данные получены', [
        'type' => $type,
        'count' => count($data)
    ]);
    
    if (empty($data)) {
        Logger::warning('Список пуст в 1С', ['type' => $type]);
        return true;
    }
    
    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
    $uidProp = $syncConfig[$type]['props']['uid'];
    
    foreach ($data as $item) {
        $uid = $item['УИД'] ?? '';
        $name = $item['Наименование'] ?? '';
        
        if (empty($uid)) {
            $stats['skipped']++;
            continue;
        }
        
        $existing = findElementByUid($iblockId, $uidProp, $uid);
        
        if ($existing) {
            // Проверяем необходимость обновления
            $needUpdate = ($existing['NAME'] !== $name);
            
            // Для банков: проверяем владельца
            if ($type === 'bank' && !empty($item['Владелец'])) {
                $ownerProp = $syncConfig[$type]['props']['owner'];
                $currentOwner = \CIBlockElement::GetProperty(
                    $iblockId, $existing['ID'], [], ['ID' => $ownerProp]
                )->Fetch();
                if (($currentOwner['VALUE'] ?? '') !== $item['Владелец']) {
                    $needUpdate = true;
                }
            }
            
            if ($needUpdate) {
                if (syncElement($type, $item, $config, $syncConfig)) {
                    $stats['updated']++;
                }
            } else {
                $stats['skipped']++;
            }
        } else {
            if (syncElement($type, $item, $config, $syncConfig)) {
                $stats['created']++;
            }
        }
    }
    
    Logger::info('=== Синхронизация завершена ===', [
        'type' => $type,
        'stats' => $stats,
        'total_in_1c' => count($data)
    ]);
    
    return true;
}

// === ЗАПУСК ===
echo " Запуск синхронизации: " . date('Y-m-d H:i:s') . "\n";

try {
    $result = [
        'bank' => runSync('bank', $oneC, $config, $syncConfig),
        'cash' => runSync('cash', $oneC, $config, $syncConfig),
        'organization' => runSync('organization', $oneC, $config, $syncConfig)
    ];
    
    echo "\n Синхронизация завершена:\n";
    echo "Счета: " . ($result['bank'] ? 'OK' : 'ERROR') . "\n";
    echo "Кассы: " . ($result['cash'] ? 'OK' : 'ERROR') . "\n";
	echo "Организации: " . ($result['organization'] ? 'OK' : 'ERROR') . "\n";
    echo "Логи: /local/1c_integration/logs/\n";
    
} catch (\Exception $e) {
    Logger::error('Критическая ошибка', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    echo "\n ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>