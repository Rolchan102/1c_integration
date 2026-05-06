<?php
/**
 * Шаг 2: Обратная синхронизация оплаты
 * Проверяет статус оплаты в 1С и обновляет стадию сделки + поле "Оплата получена" в Битрикс24
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
\Bitrix\Main\Loader::includeModule('catalog');

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
    if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'] ?? [])) {
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
        ShowError("Доступ запрещен");
        die();
    }
}

Logger::info('=== ЗАПУСК ШАГА 1: Синхронизация оплаты ===', [
    'mode' => PHP_SAPI === 'cli' ? 'cron' : 'web',
    'user_id' => PHP_SAPI === 'cli' ? 'cron' : $USER->GetID()
]);

// === ПОЛУЧЕНИЕ СПИСКА СДЕЛОК ДЛЯ ПРОВЕРКИ ОПЛАТЫ ===
$dealsToCheck = getDealsForPaymentCheck($config);

if (empty($dealsToCheck)) {
    Logger::info('Нет сделок для проверки оплаты');
    echo json_encode(
        ['success' => true, 'checked' => 0, 'updated' => 0, 'message' => 'Нет сделок для проверки'], 
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

Logger::info('Найдено сделок для проверки оплаты', ['count' => count($dealsToCheck)]);

// === ПРОВЕРКА СТАТУСА ОПЛАТЫ ДЛЯ КАЖДОЙ СДЕЛКИ ===
$checkedCount = 0;
$updatedCount = 0;
$failedCount = 0;
$updates = [];
$failures = [];

foreach ($dealsToCheck as $deal) {
    try {
        $dealId = $deal['ID'];
        $categoryId = $deal['CATEGORY_ID'];
        $currentStage = $deal['STAGE_ID'];
        $categoryIdStr = (string)($categoryId ?? '');
        $retailCat = (string)($config['deal_categories']['retail'] ?? '0');
        $wholesaleCat = (string)($config['deal_categories']['wholesale'] ?? '1');
        $paidValue = $config['payment_received']['values']['yes'];   // '260'
        $unpaidValue = $config['payment_received']['values']['no'];  // '261'
		$wasJustUpdated = false;
        $newStageId = null;
        
        Logger::debug('Проверка оплаты для сделки', [
            'deal_id' => $dealId,
            'category' => $categoryId,
            'stage' => $currentStage,
            'order_number' => $deal[$config['deal_fields']['UF_CRM_INVOICE_NUMBER']] ?? 'неизвестен'
        ]);
        
        // Шаг 1: Проверка статуса оплаты в 1С
        $paymentStatus = $oneC->checkInvoicePaymentStatus($dealId);
        
        if ($paymentStatus === false) {
            // Не критическая ошибка — возможно, заказ ещё не создан в 1С
            Logger::debug('Статус оплаты не получен для сделки', [
                'deal_id' => $dealId,
                'reason' => 'заказ не найден в 1С или ошибка связи'
            ]);
            $checkedCount++;
            continue;
        }

        $checkedCount++;
        
        // Шаг 2: Анализ статуса оплаты
        $isPaid = $paymentStatus['paid'] ?? false;
        $amountPaid = $paymentStatus['amount_paid'] ?? 0;
        $invoiceNumber = $paymentStatus['invoice_number'] ?? '';
        
        Logger::debug('Статус оплаты получен', [
            'deal_id' => $dealId,
            'is_paid' => $isPaid ? 'Да' : 'Нет',
            'amount_paid' => $amountPaid,
            'invoice_number' => $invoiceNumber
        ]);

		// Шаг 3: Принятие решения об обновлении стадии
		$needsUpdate = false;
		$newStageId = null;

		if ($isPaid) {
			$currentPaymentStatus = $deal[$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED']] ?? '0';
			$paidValue = $config['payment_received']['values']['yes'];   // '260'

			if ($currentPaymentStatus !== $paidValue) {
				$needsUpdate = true;

				Logger::debug('Выбор целевой стадии', [
					'deal_id' => $dealId,
					'category_id' => $categoryId,
					'category_str' => $categoryIdStr,
					'retail_cat' => $retailCat,
					'wholesale_cat' => $wholesaleCat
				]);

				if ($categoryIdStr === $retailCat) {
					$newStageId = $config['paid_stages']['retail'] ?? 'EXECUTING';
				} elseif ($categoryIdStr === $wholesaleCat) {
					$newStageId = $config['paid_stages']['wholesale'] ?? 'C1:1';
				}

				// Фоллбэк: если категория не определилась — используем розницу
				if (!$newStageId) {
					$newStageId = $config['paid_stages']['retail'] ?? 'EXECUTING';
					Logger::warning('Категория сделки не определена, используем фоллбэк (розница)', [
						'deal_id' => $dealId,
						'fallback_stage' => $newStageId
					]);
				}

				$updateFields = [
					'STAGE_ID' => $newStageId,
					$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED'] => $paidValue,
					$config['deal_fields']['UF_CRM_PAYMENT_AMOUNT'] => $amountPaid
				];

				$updates[] = [
					'deal_id' => $dealId,
					'old_stage' => $currentStage,
					'new_stage' => $newStageId,
					'amount_paid' => $amountPaid
				];
			}
		}

		// Шаг 4: Выполнение обновления
		if ($needsUpdate && $newStageId) {
			$updated = updateDealViaRest($dealId, $updateFields, $config);
			
			if ($updated) {
				// 🔧 ПРОВЕРКА: действительно ли стадия обновилась? (защита от "тихих" сбоев)
				usleep(150000); // 150 мс на репликацию БД Битрикс24
				$checkDeal = \CCrmDeal::GetById($dealId);
				$checkDeal = is_array($checkDeal) ? $checkDeal : ($checkDeal ? $checkDeal->Fetch() : null);
				
				$actualStage = $checkDeal['STAGE_ID'] ?? '';
				$actualPayment = $checkDeal[$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED']] ?? '';
				
				// Если стадия НЕ изменилась — пробуем обновить только её отдельным запросом
				if ($actualStage !== $newStageId) {
					Logger::warning('Стадия не обновилась после первого запроса, пробую повтор', [
						'deal_id' => $dealId,
						'expected_stage' => $newStageId,
						'actual_stage' => $actualStage
					]);
					
					$retryFields = ['STAGE_ID' => $newStageId];
					$retry = updateDealViaRest($dealId, $retryFields, $config);
					
					if ($retry) {
						// Ещё раз перечитываем для подтверждения
						usleep(100000);
						$finalCheck = \CCrmDeal::GetById($dealId);
						$finalCheck = is_array($finalCheck) ? $finalCheck : ($finalCheck ? $finalCheck->Fetch() : null);
						
						if (($finalCheck['STAGE_ID'] ?? '') === $newStageId) {
							Logger::info('✅ Стадия обновлена повторным запросом', ['deal_id' => $dealId]);
							$updatedCount++;
						} else {
							Logger::error('❌ Даже повторный запрос не обновил стадию', [
								'deal_id' => $dealId,
								'expected' => $newStageId,
								'actual' => $finalCheck['STAGE_ID'] ?? 'UNKNOWN'
							]);
							$failedCount++;
						}
					} else {
						Logger::error('❌ Повторный запрос обновления стадии не удался', ['deal_id' => $dealId]);
						$failedCount++;
					}
				} else {
					// ✅ Всё ок, стадия действительно изменилась
					$updatedCount++;
					$wasJustUpdated = true;
					Logger::info('Стадия сделки обновлена после получения оплаты', [
						'deal_id' => $dealId,
						'old_stage' => $currentStage,
						'new_stage' => $newStageId,
						'amount_paid' => $amountPaid,
						'verified' => true
					]);
				}
			} else {
				Logger::error('Не удалось обновить сделку через REST', [
					'deal_id' => $dealId,
					'fields' => $updateFields
				]);
				$failedCount++;
			}
		}

        // АВАРИЙНОЕ ОБНОВЛЕНИЕ: если оплата уже = "Да", но стадия всё ещё "на проверке"
        // Это ловит сделки, которые "застряли" из-за временных сбоев
        if (!$wasJustUpdated) {
    		$currentPaymentStatus = $deal[$config['deal_fields']['UF_CRM_PAYMENT_RECEIVED']] ?? '';
			$checkStage = $config['payment_check_stages'][$categoryIdStr] ?? null;
			
			if ($currentPaymentStatus === $paidValue && $checkStage && $currentStage === $checkStage) {
				$targetStage = $config['paid_stages'][$categoryIdStr] ?? null;
				
				if ($targetStage) {
					Logger::warning('Сделка "застряла": оплата=Да, но стадия не обновлена — аварийное исправление', [
						'deal_id' => $dealId,
						'current_stage' => $currentStage,
						'target_stage' => $targetStage,
						'payment_status' => $currentPaymentStatus
					]);
					
					$fixFields = ['STAGE_ID' => $targetStage];
					$fixed = updateDealViaRest($dealId, $fixFields, $config);
					
					if ($fixed) {
						// Перечитываем для подтверждения
						usleep(150000);
						$verifyDeal = \CCrmDeal::GetById($dealId);
						$verifyDeal = is_array($verifyDeal) ? $verifyDeal : ($verifyDeal ? $verifyDeal->Fetch() : null);
						
						if (($verifyDeal['STAGE_ID'] ?? '') === $targetStage) {
							Logger::info('✅ "Застрявшая" сделка исправлена аварийным обновлением', [
								'deal_id' => $dealId,
								'new_stage' => $targetStage
							]);
							$updatedCount++;
						} else {
							Logger::error('❌ Аварийное обновление не применилось', [
								'deal_id' => $dealId,
								'expected' => $targetStage,
								'actual' => $verifyDeal['STAGE_ID'] ?? 'UNKNOWN'
							]);
						}
					} else {
						Logger::error('❌ Не удалось выполнить аварийное обновление стадии', [
							'deal_id' => $dealId,
							'target_stage' => $targetStage
						]);
					}
				}
			}
		}

        // Пауза между запросами к 1С
        usleep(150000); // 150 мс

    } catch (\Exception $e) {
        $failedCount++;
        $errorMessage = $e->getMessage();
        $failures[] = [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ];
        
        Logger::error('Ошибка проверки/обновления оплаты для сделки', [
            'deal_id' => $deal['ID'] ?? 'unknown',
            'error' => $errorMessage
        ]);
    }
}

// === ИТОГИ ===
Logger::info('=== ЗАВЕРШЕНИЕ ШАГА 1 ===', [
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total' => count($dealsToCheck)
]);

// Вывод результата
$result = [
    'success' => $failedCount === 0,
    'checked' => $checkedCount,
    'updated' => $updatedCount,
    'failed' => $failedCount,
    'total' => count($dealsToCheck),
    'updates' => $updates,
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
 * Получение списка сделок для проверки статуса оплаты
 * 
 * Критерии отбора:
 * 1. Розница (категория 0): стадия "Договор/счет выставлен" (PREPAYMENT_INVOICE)
 *    И поле "Оплата получена" = "Нет" (0)
 * 
 * 2. Опт (категория 1): стадии "Договор/счет выставлен" (C1:EXECUTING) 
 *    ИЛИ "Передано в отгрузку" (C1:1)
 *    И поле "Оплата получена" = "Нет" (0)
 * 
 * 3. Поле "№ заказа в 1С" заполнено (иначе нет смысла проверять)
 * 
 * @param array $config Конфигурация
 * @return array Массив сделок
 */
function getDealsForPaymentCheck($config) {
    $deals = [];
    $orderField = $config['deal_fields']['UF_CRM_INVOICE_NUMBER'];
    $paymentField = $config['deal_fields']['UF_CRM_PAYMENT_RECEIVED'];
    $unpaidValue = $config['payment_received']['values']['no'];  // '261'

    // === Запрос 1: Розница ===
    $filterRetail = [
        'CATEGORY_ID' => $config['deal_categories']['retail'],
        'STAGE_ID' => $config['payment_check_stages']['retail'],  // ← Из нового конфига!
        $paymentField => $unpaidValue,
        "!$orderField" => false  // поле не пустое
    ];
    
    $dbRetail = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filterRetail,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $paymentField],
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );
    
    while ($deal = $dbRetail->Fetch()) {
        if (!empty($deal[$orderField])) {
            $deals[] = $deal;
        }
    }
    
    // === Запрос 2: Опт (стадия из payment_check_stages) ===
    $filterWholesale = [
        'CATEGORY_ID' => $config['deal_categories']['wholesale'],
        'STAGE_ID' => $config['payment_check_stages']['wholesale'],  // ← Исправлено!
        $paymentField => $unpaidValue,
        "!$orderField" => false
    ];
    
    $dbWholesale = \CCrmDeal::GetList(
        ['DATE_MODIFY' => 'ASC'],
        $filterWholesale,
        ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $paymentField],
        false,
        ['nTopCount' => $config['batch_size'] ?? 20]
    );
    
    while ($deal = $dbWholesale->Fetch()) {
        if (!empty($deal[$orderField])) {
            $deals[] = $deal;
        }
    }
    
    return $deals;
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
?>