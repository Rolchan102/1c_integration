<?php
/**
 * Шаг 1 (FIX): Аварийное исправление "зависших" сделок
 * 
 * Находит сделки, где:
 * - Поле "Оплата получена" = ДА (260)
 * - Но стадия всё ещё соответствует "ожиданию оплаты" (из payment_check_stages)
 * 
 * Принудительно обновляет стадию на целевую (из paid_stages)
 */

if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");

if (PHP_SAPI === 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        $USER = new \CUser();
        $USER->Authorize(1);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('crm');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);

if (PHP_SAPI !== 'cli') {
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

Logger::info('=== ЗАПУСК FIX: Исправление зависших сделок ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : $USER->GetID()
]);

$stuckDeals = getStuckDeals($config);

if (empty($stuckDeals)) {
    Logger::info('Нет зависших сделок для исправления');
    echo json_encode(
        ['success' => true, 'found' => 0, 'fixed' => 0, 'message' => 'Зависшие сделки не найдены'], 
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

Logger::info('Найдено зависших сделок', ['count' => count($stuckDeals)]);

$fixedCount = 0;
$failedCount = 0;
$fixedList = [];
$failures = [];

$paidValue = $config['payment_received']['values']['yes'];

foreach ($stuckDeals as $deal) {
    try {
        $dealId = (int)$deal['ID'];
        
        // 🔧 КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: перечитываем сделку полностью для гарантированного получения CATEGORY_ID
        $fullDeal = \CCrmDeal::GetById($dealId);
        $fullDeal = is_array($fullDeal) ? $fullDeal : ($fullDeal ? $fullDeal->Fetch() : null);
        
        if (!$fullDeal) {
            Logger::error('Не удалось загрузить полную информацию о сделке', ['deal_id' => $dealId]);
            $failedCount++;
            continue;
        }
        
        $categoryId = $fullDeal['CATEGORY_ID'] ?? null;
        $currentStage = $fullDeal['STAGE_ID'] ?? '';
        $categoryIdStr = $categoryId !== null ? (string)$categoryId : '';
        
        $retailCat = (string)($config['deal_categories']['retail'] ?? '0');
        $wholesaleCat = (string)($config['deal_categories']['wholesale'] ?? '1');
        
        Logger::debug('Обработка зависшей сделки', [
            'deal_id' => $dealId,
            'category_id_raw' => $categoryId,
            'category_id_str' => $categoryIdStr,
            'current_stage' => $currentStage,
            'order_number' => $fullDeal[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']] ?? 'неизвестен'
        ]);
        
        // Определяем целевую стадию
        $targetStage = null;
        $categoryName = null;
        
        if ($categoryIdStr === $retailCat) {
            $targetStage = $config['paid_stages']['retail'] ?? 'EXECUTING';
            $categoryName = 'retail';
        } elseif ($categoryIdStr === $wholesaleCat) {
            $targetStage = $config['paid_stages']['wholesale'] ?? 'C1:FINAL_INVOICE';
            $categoryName = 'wholesale';
        }
        
        if (!$targetStage) {
            Logger::warning('Не удалось определить целевую стадию', [
                'deal_id' => $dealId,
                'category_id' => $categoryId,
                'category_id_str' => $categoryIdStr,
                'expected_retail' => $retailCat,
                'expected_wholesale' => $wholesaleCat
            ]);
            $failedCount++;
            continue;
        }
        
        $updateFields = ['STAGE_ID' => $targetStage];
        $updated = updateDealViaRest($dealId, $updateFields, $config);
        
        if ($updated) {
            usleep(150000);
            $checkDeal = \CCrmDeal::GetById($dealId);
            $checkDeal = is_array($checkDeal) ? $checkDeal : ($checkDeal ? $checkDeal->Fetch() : null);
            $actualStage = $checkDeal['STAGE_ID'] ?? '';
            
            if ($actualStage === $targetStage) {
                $fixedCount++;
                $fixedList[] = [
                    'deal_id' => $dealId,
                    'title' => $fullDeal['TITLE'] ?? '',
                    'old_stage' => $currentStage,
                    'new_stage' => $targetStage,
                    'category' => $categoryName
                ];
                Logger::info('✅ Сделка исправлена', [
                    'deal_id' => $dealId,
                    'old_stage' => $currentStage,
                    'new_stage' => $targetStage,
                    'category' => $categoryName
                ]);
            } else {
                Logger::warning('Стадия не обновилась с первого раза, пробую повтор', [
                    'deal_id' => $dealId, 'expected' => $targetStage, 'actual' => $actualStage
                ]);
                
                $retry = updateDealViaRest($dealId, $updateFields, $config);
                if ($retry) {
                    usleep(100000);
                    $finalCheck = \CCrmDeal::GetById($dealId);
                    $finalCheck = is_array($finalCheck) ? $finalCheck : ($finalCheck ? $finalCheck->Fetch() : null);
                    
                    if (($finalCheck['STAGE_ID'] ?? '') === $targetStage) {
                        $fixedCount++;
                        $fixedList[] = [
                            'deal_id' => $dealId, 'title' => $fullDeal['TITLE'] ?? '',
                            'old_stage' => $currentStage, 'new_stage' => $targetStage,
                            'category' => $categoryName, 'retry' => true
                        ];
                        Logger::info('✅ Сделка исправлена после повторного запроса', ['deal_id' => $dealId]);
                    } else {
                        $failedCount++;
                        $failures[] = ['deal_id' => $dealId, 'error' => 'Стадия не обновилась даже после повтора'];
                        Logger::error('❌ Не удалось исправить сделку', ['deal_id' => $dealId, 'expected' => $targetStage, 'actual' => $finalCheck['STAGE_ID'] ?? 'UNKNOWN']);
                    }
                } else {
                    $failedCount++;
                    $failures[] = ['deal_id' => $dealId, 'error' => 'Повторный запрос не удался'];
                    Logger::error('❌ Повторный запрос обновления не удался', ['deal_id' => $dealId]);
                }
            }
        } else {
            $failedCount++;
            $failures[] = ['deal_id' => $dealId, 'error' => 'Не удалось обновить сделку через REST'];
            Logger::error('Не удалось обновить сделку через REST', ['deal_id' => $dealId, 'fields' => $updateFields]);
        }
        
        usleep(100000);
        
    } catch (\Exception $e) {
        $failedCount++;
        $failures[] = ['deal_id' => $deal['ID'] ?? 'unknown', 'error' => $e->getMessage()];
        Logger::error('Ошибка при исправлении сделки', ['deal_id' => $deal['ID'] ?? 'unknown', 'error' => $e->getMessage()]);
    }
}

Logger::info('=== ЗАВЕРШЕНИЕ FIX ===', ['found' => count($stuckDeals), 'fixed' => $fixedCount, 'failed' => $failedCount]);

$result = [
    'success' => $failedCount === 0,
    'found' => count($stuckDeals),
    'fixed' => $fixedCount,
    'failed' => $failedCount,
    'fixed_deals' => $fixedList,
    'failures' => $failures,
    'timestamp' => date('Y-m-d H:i:s')
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * Получение списка "зависших" сделок
 */
function getStuckDeals($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'];
    $paymentField = $config['deal_fields']['UF_CRM_PAYMENT_RECEIVED'];
    $paidValue = $config['payment_received']['values']['yes'];
    
    // Розница
    $filterRetail = [
        'CATEGORY_ID' => $config['deal_categories']['retail'],
        'STAGE_ID' => $config['payment_check_stages']['retail'],
        $paymentField => $paidValue,
        "!$orderField" => false
    ];
    $dbRetail = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'], $filterRetail,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $paymentField],
        false, ['nTopCount' => $config['batch_size'] ?? 20]
    );
    while ($deal = $dbRetail->Fetch()) {
        if (!empty($deal[$orderField])) $deals[] = $deal;
    }
    
    // Опт
    $filterWholesale = [
        'CATEGORY_ID' => $config['deal_categories']['wholesale'],
        'STAGE_ID' => $config['payment_check_stages']['wholesale'],
        $paymentField => $paidValue,
        "!$orderField" => false
    ];
    $dbWholesale = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'], $filterWholesale,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $paymentField],
        false, ['nTopCount' => $config['batch_size'] ?? 20]
    );
    while ($deal = $dbWholesale->Fetch()) {
        if (!empty($deal[$orderField])) $deals[] = $deal;
    }
    
    return $deals;
}

/**
 * Обновление сделки через REST API
 */
function updateDealViaRest($dealId, $fields, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/crm.deal.update.json';
        $postData = http_build_query(['id' => (int)$dealId, 'fields' => $fields]);
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
        \Integration\Logger::error('Ошибка обновления сделки через REST', ['deal_id' => $dealId, 'error' => $e->getMessage()]);
        return false;
    }
}
?>