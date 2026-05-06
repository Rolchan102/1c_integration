<?php
/**
 * Синхронизация товаров из 1С в Bitrix24
 * - Уникальность: УИД Номенклатуры (1084) + УИД Склада (1089)
 * - Остаток и Резерв записываются напрямую (без суммирования)
 * - Только товары с остатком > 0 (mode='10')
 * 
 * Запуск: по cron каждые 15-30 минут
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
\Bitrix\Main\Loader::includeModule('catalog');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

set_time_limit(3600);
ini_set('memory_limit', '2G');

$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);
$oneC = new OneCClient($config);

$fluoriteCategoryMap = $config['fluorite_category_map'] ?? [];

Logger::info('=== НАЧАЛО СИНХРОНИЗАЦИИ (Номенклатура + Склад + Резерв) ===');

$itemsFrom1C = $oneC->getAllItems('10');

Logger::info('getAllItems вернул данные', [
    'type' => gettype($itemsFrom1C),
    'count' => is_array($itemsFrom1C) ? count($itemsFrom1C) : 'N/A',
    'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
]);

if (empty($itemsFrom1C)) {
    Logger::info('Список товаров пуст (0 элементов)');
    exit;
}

if ($itemsFrom1C === false || !is_array($itemsFrom1C)) {
    Logger::error('Не удалось получить товары из 1С', ['mode' => '1']);
    exit;
}

// 🔧 ГРУППИРОВКА: по связке УИД Номенклатуры + УИД Склада (без суммирования!)
Logger::info('Группировка товаров', ['before' => count($itemsFrom1C)]);

$groupedItems = [];
foreach ($itemsFrom1C as $item) {
    $uid = $item['УИДНоменклатуры'] ?? '';
    $warehouseUid = $item['УИДСклада'] ?? '';
    
    if (empty($uid)) continue;
    
    $compositeKey = $uid . '|' . $warehouseUid;
    
    if (!isset($groupedItems[$compositeKey])) {
        $groupedItems[$compositeKey] = $item;
    }
}

$itemsFrom1C = array_values($groupedItems);

Logger::info('После группировки', ['unique_products' => count($itemsFrom1C)]);

// 🔹 Кеширование разделов
$IBLOCK_ID = 14;
$sectionCache = []; 
$res = \CIBlockSection::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => $IBLOCK_ID],
    false,
    ['ID', 'NAME', 'CODE']
);
while ($sec = $res->GetNext()) {
    $sectionCache[$sec['NAME']] = $sec['ID'];
}

$created = 0;
$updated = 0;
$errors = 0;
$sectionsCreated = 0;

Logger::info('Начало обработки товаров', ['total' => count($itemsFrom1C)]);

foreach ($itemsFrom1C as $index => $item1C) {
    try {
        $uid = $item1C['УИДНоменклатуры'] ?? '';
        $warehouseUid = $item1C['УИДСклада'] ?? '';
        $code = $item1C['Код'] ?? '';
        $name = $item1C['Наименование'] ?? '';
        $groupName = $item1C['Группа'] ?? '';
        $uid_supplier = $item1C['DNK_Поставщик'] ?? '';
        
        if (empty($uid)) {
            Logger::warning('Пропуск: пустой УИД номенклатуры', ['code' => $code]);
            continue;
        }
        
        // 1. Работа с разделом
        $sectionId = 0;
        if (!empty($groupName)) {
            $sectionId = ensureSectionExists($groupName, $IBLOCK_ID, $sectionCache);
            if ($sectionId && !isset($sectionCache[$groupName])) {
                $sectionCache[$groupName] = $sectionId;
                $sectionsCreated++;
            }
        }

        // 2. Поиск товара по составному ключу
        $existingProduct = findProductByCompositeUid($uid, $warehouseUid);
        
        if ($existingProduct) {
            $result = updateProduct($existingProduct['ID'], $item1C, $sectionId, $warehouseUid, $uid_supplier, $config, $fluoriteCategoryMap);
            if ($result) {
                $updated++;
            } else {
                $errors++;
                Logger::error('Ошибка обновления', ['id' => $existingProduct['ID']]);
            }
        } else {
            $productId = createProduct($item1C, $sectionId, $warehouseUid, $uid_supplier, $config, $fluoriteCategoryMap);
            if ($productId) {
                $created++;
            } else {
                $errors++;
                Logger::error('Ошибка создания', ['code_1c' => $code]);
            }
        }
        
    } catch (\Exception $e) {
        $errors++;
        Logger::error('Исключение', [
            'code_1c' => $item1C['Код'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
    }
    
    usleep(10000);
    
    if (($index + 1) % 500 === 0) {
        Logger::info('Прогресс', [
            'processed' => $index + 1,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ]);
    }
}

Logger::info('=== ЗАВЕРШЕНИЕ ===', [
    'created' => $created,
    'updated' => $updated,
    'errors' => $errors,
    'new_sections' => $sectionsCreated
]);

// ===== ФУНКЦИИ =====

function findProductByCompositeUid($uid, $warehouseUid) {
    if (empty($uid)) return false;
    
    $filter = [
        'IBLOCK_ID' => 14,
        'PROPERTY_1084' => $uid,
        'PROPERTY_1089' => $warehouseUid
    ];
    
    $res = \CIBlockElement::GetList(
        [],
        $filter,
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_SECTION_ID']
    );
    
    return $res->Fetch();
}

function ensureSectionExists($name, $iblockId, &$cache) {
    if (empty($name)) return 0;
    
    if (isset($cache[$name])) return $cache[$name];
    
    $res = \CIBlockSection::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'NAME' => $name],
        false,
        ['ID']
    );
    if ($section = $res->GetNext()) {
        $cache[$name] = $section['ID'];
        return $section['ID'];
    }
    
    $el = new \CIBlockSection;
    $fields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => $name,
        'CODE' => \CUtil::Translit($name, 'ru', [
            'max_len' => 255, 'change_case' => 'L',
            'replace_space' => '-', 'remove_other' => 'Y'
        ]),
        'ACTIVE' => 'Y',
        'SORT' => 100
    ];
    
    $newId = $el->Add($fields);
    if ($newId) {
        Logger::info('Создан раздел', ['name' => $name, 'id' => $newId]);
        return $newId;
    } else {
        Logger::error('Ошибка создания раздела', ['name' => $name, 'error' => $el->LAST_ERROR]);
        return 0;
    }
}

function createProduct($item1C, $sectionId = 0, $warehouseUid = '', $uid_supplier = '', $config = [], $fluoriteMap = []) {
    $iblockId = 14;
    $element = new \CIBlockElement;
    
    $productName = $item1C['Наименование'] ?? '';
    
    $fields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => $productName, 
        'CODE' => transliterate($item1C['Код'] ?? '') . ($warehouseUid ? '-' . substr(md5($warehouseUid), 0, 4) : ''),
        'ACTIVE' => 'Y',
        'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : null,
        'PROPERTY_VALUES' => [
            1084 => $item1C['УИДНоменклатуры'] ?? '',
            1089 => !empty($warehouseUid) ? $warehouseUid : '',
            1085 => $item1C['Код'] ?? '',
            167  => $item1C['DNK_Бренд'] ?? '',
			169  => $item1C['Артикул'] ?? '',
			1212  => $uid_supplier,
			1083 => (
				($xmlId = getFluoriteCategoryXmlId($item1C['DNK_КатегорияФлюорита'] ?? '', $fluoriteMap)) !== false 
				? $xmlId 
				: ''
			),
        ]
    ];
    
    if (!empty($item1C['НаименованиеПолное'])) {
        $fields['DETAIL_TEXT'] = $item1C['НаименованиеПолное'];
    }
    
    $productId = $element->Add($fields);
    
    if (!$productId) {
        Logger::error('Ошибка Add', [
            'error' => $element->LAST_ERROR,
            'uid' => $item1C['УИДНоменклатуры'] ?? '',
            'warehouse' => $warehouseUid
        ]);
        return false;
    }

    // Обновляем каталог: Остаток + Резерв
    if (\Bitrix\Main\Loader::includeModule('catalog')) {
        $catalogData = [
            'QUANTITY' => (int)($item1C['Остаток'] ?? 0),
            'QUANTITY_RESERVED' => (int)($item1C['Резерв'] ?? 0), // 🔹 Новое!
            'AVAILABLE' => ((int)($item1C['Остаток'] ?? 0) > 0) ? 'Y' : 'N'
        ];
        
        $catalogProduct = \CCatalogProduct::GetByID($productId);
        
        if ($catalogProduct) {
            \CCatalogProduct::Update($productId, $catalogData);
        } else {
            \CCatalogProduct::Add(['ID' => $productId] + $catalogData);
        }
    }
    
    $prices1C = $item1C['Цены'] ?? [];
    updateProductPrices($productId, $prices1C, $config);
    
    return $productId;
}

function updateProduct($productId, $item1C, $sectionId = 0, $warehouseUid = '', $uid_supplier = '', $config = [], $fluoriteMap = []) {
    $element = new \CIBlockElement;

    $fields = [
        'NAME' => $item1C['Наименование'] ?? '',
        'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
        'PROPERTY_VALUES' => [
            1084 => $item1C['УИДНоменклатуры'] ?? '',
            1089 => !empty($warehouseUid) ? $warehouseUid : '',
            1085 => $item1C['Код'] ?? '',
            167  => $item1C['DNK_Бренд'] ?? '',
			169  => $item1C['Артикул'] ?? '',
			1212  => $uid_supplier,
			1083 => (
				($xmlId = getFluoriteCategoryXmlId($item1C['DNK_КатегорияФлюорита'] ?? '', $fluoriteMap)) !== false 
				? $xmlId 
				: ''
			),
        ]
    ];
    
    if (!empty($item1C['НаименованиеПолное'])) {
        $fields['DETAIL_TEXT'] = $item1C['НаименованиеПолное'];
    }
    
    $result = $element->Update($productId, $fields);
    
    if (!$result) {
        Logger::error('Ошибка Update', ['id' => $productId, 'error' => $element->LAST_ERROR]);
        return false;
    }
    
    // 🔧 Обновляем каталог: Остаток + Резерв
    if (\Bitrix\Main\Loader::includeModule('catalog')) {
        $catalogData = [
            'QUANTITY' => (int)($item1C['Остаток'] ?? 0),
            'QUANTITY_RESERVED' => (int)($item1C['Резерв'] ?? 0), // 🔹 Новое!
        ];
        
        \CCatalogProduct::Update($productId, $catalogData);
    }

    $prices1C = $item1C['Цены'] ?? [];
    updateProductPrices($productId, $prices1C, $config);
    
    return true;
}

function transliterate($string) {
    return \CUtil::Translit($string, 'ru', [
        'max_len' => 255,
        'change_case' => 'L',
        'replace_space' => '-',
        'remove_other' => 'Y'
    ]);
}

/**
 * Обновление цены "Розница" из 1С в базовую цену Битрикс24
 * 
 * @param int $productId ID товара в Битрикс24
 * @param array $prices1C Массив цен из 1С (поле "Цены")
 * @param array $config Конфигурация
 * @return bool Результат операции
 */
function updateProductPrices($productId, $prices1C, $config) {
    if (!\Bitrix\Main\Loader::includeModule('catalog')) {
        Logger::error('Модуль catalog не подключён', ['product_id' => $productId]);
        return false;
    }
    
    if (!is_array($prices1C) || empty($prices1C)) {
        return true;
    }
    
    $currency = $config['currency'] ?? 'RUB';
    $priceTypesMap = $config['price_types'] ?? [];
    
    foreach ($prices1C as $price1C) {
        $priceTypeName = $price1C['ВидЦен'] ?? '';
        $priceValue = (float)($price1C['Цена'] ?? 0);
        
        // Пропускаем, если цена невалидна
        if ($priceValue <= 0 || empty($priceTypeName)) {
            continue;
        }
        
        // 🔹 Работаем ТОЛЬКО с типом "Розница"
        if ($priceTypeName !== 'Розница') {
            continue;
        }
        
        // Получаем ID типа цены из конфига (для "Розница" это ID=1)
        $priceTypeId = $priceTypesMap[$priceTypeName] ?? null;
        
        if (!$priceTypeId) {
            Logger::warning('Тип цены не настроен в конфиге', [
                'product_id' => $productId,
                'price_type' => $priceTypeName
            ]);
            continue;
        }
        
        // Проверяем существующую цену в Битрикс
        $dbPrice = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceTypeId],
            false, false, ['ID', 'PRICE', 'CURRENCY']
        );
        
        $priceData = [
            'PRODUCT_ID' => $productId,
            'CATALOG_GROUP_ID' => $priceTypeId,
            'PRICE' => $priceValue,
            'CURRENCY' => $currency,
            'EXTRA_ID' => false,
            'VAT_ID' => 0,
            'VAT_INCLUDED' => 'N',
        ];
        
        if ($existing = $dbPrice->Fetch()) {
            // Обновляем, только если цена или валюта изменились
            if ((float)$existing['PRICE'] !== $priceValue || $existing['CURRENCY'] !== $currency) {
                \CPrice::Update($existing['ID'], $priceData);
                Logger::info('Цена обновлена', [
                    'product_id' => $productId,
                    'price_type' => $priceTypeName,
                    'old_price' => $existing['PRICE'],
                    'new_price' => $priceValue,
                    'currency' => $currency
                ]);
            }
        } else {
            // Создаём новую цену
            $newId = \CPrice::Add($priceData);
            if ($newId) {
                Logger::info('Цена добавлена', [
                    'product_id' => $productId,
                    'price_type' => $priceTypeName,
                    'price' => $priceValue,
                    'currency' => $currency,
                    'price_id' => $newId
                ]);
            } else {
                Logger::error('Ошибка добавления цены', [
                    'product_id' => $productId,
                    'price_type' => $priceTypeName,
                    'error' => $APPLICATION->GetException()?->GetString() ?? 'unknown'
                ]);
            }
        }
    }

    return true;
}

/**
 * Возвращает XML_ID значения списка для свойства 1083 (Коллекция флюорита)
 * @param string $valueFrom1c Значение из 1С (напр. "Алебастр")
 * @param array $map Карта соответствий
 * @return string|false XML_ID или false, если не найдено
 */
function getFluoriteCategoryXmlId($valueFrom1c, $map) {
    $valueFrom1c = trim($valueFrom1c);
    return $map[$valueFrom1c] ?? false;
}