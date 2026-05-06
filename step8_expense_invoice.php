<?php
/**
 * 🔧 Шаг 8: Расходная накладная при завершении сделки (/ExpInvoice)
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
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

\Bitrix\Main\Loader::includeModule('crm');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');

use Integration\Logger;

// Проверка доступа
if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!isset($USER) || !$USER->IsAuthorized()) {
        header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);

// 🔧 ЖЁСТКИЕ КОДЫ ПОЛЕЙ (как в вашем Б24)
define('FIELD_1C_ORDER_ID', 'UF_CRM_1C_ORDER_ID');
define('FIELD_SHIPPING_DOC_NUM', 'UF_CRM_SHIPPING_DOC_NUM');
define('FIELD_EXPENSE_DATE', 'UF_CRM_EXPENSE_DATE');

Logger::info('=== ЗАПУСК ШАГА 8 ===');

$dealsToProcess = getDealsForExpenseInvoice($config);

if (empty($dealsToProcess)) {
    Logger::info('Нет сделок для обработки');
    outputResult(0, 0, 0, 0, []);
    exit;
}

Logger::info('Найдено сделок', ['count' => count($dealsToProcess)]);

$processedCount = 0; $createdCount = 0; $failedCount = 0; $failures = [];

foreach ($dealsToProcess as $deal) {
    try {
        $dealId = $deal['ID'];
        
        // 🔧 Используем жёсткие коды полей
        $orderNumber = $deal[FIELD_1C_ORDER_ID] ?? '';
        $shippingDocNumber = $deal[FIELD_SHIPPING_DOC_NUM] ?? '';
        $category = $deal['CATEGORY_ID'] ?? '0';
        $stage = $deal['STAGE_ID'];

        // Проверка условий
        if (empty($orderNumber) || $orderNumber === '0') {
            throw new \Exception('Заказ покупателя не найден в 1С (поле ' . FIELD_1C_ORDER_ID . ' пустое)');
        }
        if (!empty($shippingDocNumber) && $shippingDocNumber !== '0') {
            Logger::info('Расходная уже привязана', ['deal_id' => $dealId, 'doc' => $shippingDocNumber]);
            continue;
        }

        // Проверка в 1С
        $existingInvoice = checkExpenseInvoiceIn1C($dealId, $config);
        
        if ($existingInvoice && !empty($existingInvoice['Номер'])) {
            Logger::info('Расходная уже есть в 1С', ['deal_id' => $dealId, 'number' => $existingInvoice['Номер']]);
            $expenseData = $existingInvoice;
            $isNew = false;
        } else {
            Logger::info('Создание расходной в 1С', ['deal_id' => $dealId]);
            $expenseData = createExpenseInvoiceIn1C($dealId, $config);
            if (!$expenseData || empty($expenseData['Номер'])) {
                throw new \Exception('Не удалось создать или получить номер расходной накладной в 1С');
            }
            Logger::info('Расходная создана', ['deal_id' => $dealId, 'number' => $expenseData['Номер']]);
            $isNew = true;
        }

        // Обновление полей сделки
        $expenseDate = !empty($expenseData['Дата']) 
            ? preg_replace('/\s.*$/', '', trim($expenseData['Дата'])) 
            : date('d.m.Y');

        $updateFields = [
            FIELD_SHIPPING_DOC_NUM => $expenseData['Номер'],
            FIELD_EXPENSE_DATE => $expenseDate,
        ];

        $updated = updateDealViaRest($dealId, $updateFields, $config);
        if (!$updated) {
            throw new \Exception('Ошибка обновления полей сделки через REST API');
        }

        // === ПЕЧАТНЫЕ ФОРМЫ: скачиваем файлы ===
        $docs = [];
        
        $updPdf = getShippingDocPdfFrom1C($dealId, 'upd', $config);
        if ($updPdf) {
            $docs['upd'] = ['content' => $updPdf, 'filename' => "УПД_{$expenseData['Номер']}.pdf"];
        }
        
        $torg12Pdf = getShippingDocPdfFrom1C($dealId, 'torg12', $config);
        if ($torg12Pdf) {
            $docs['torg12'] = ['content' => $torg12Pdf, 'filename' => "Расходная_{$expenseData['Номер']}.pdf"];
        }

        // === ТАЙМЛАЙН: одна запись с комментарием + все файлы ===
        if (!empty($docs)) {
            // Формируем комментарий в нужном формате
            $timelineComment = "Расходная накладная №{$expenseData['Номер']} от {$expenseDate}";
            
            // Загружаем все файлы в одной записи
            uploadPdfsToTimelineCombined($dealId, $timelineComment, $docs, $config);
        }

        $processedCount++;
        if ($isNew) $createdCount++;
        Logger::info('Сделка обработана', ['deal_id' => $dealId, 'action' => $isNew ? 'created' : 'found']);

    } catch (\Exception $e) {
        $failedCount++;
        $failures[] = ['deal_id' => $deal['ID'] ?? 'unknown', 'error' => $e->getMessage()];
        Logger::error('Ошибка обработки', ['deal_id' => $deal['ID'] ?? 'unknown', 'error' => $e->getMessage()]);
    }
    usleep(200000);
}

Logger::info('=== ЗАВЕРШЕНИЕ ===', ['processed'=>$processedCount,'created'=>$createdCount,'failed'=>$failedCount]);
outputResult($processedCount, $createdCount, $failedCount, count($dealsToProcess), $failures);


// ===== ФУНКЦИИ =====

function getDealsForExpenseInvoice($config) {
    $deals = [];
    $wonStages = $config['won_stages'] ?? ['retail'=>'WON','wholesale'=>'C1:WON'];
    $catRetail = $config['deal_categories']['retail'] ?? '0';
    $catWholesale = $config['deal_categories']['wholesale'] ?? '1';
    
    // 🔧 Жёсткие коды полей
    $orderField = FIELD_1C_ORDER_ID;
    $shippingDocField = FIELD_SHIPPING_DOC_NUM;
    $selectFields = ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', $orderField, $shippingDocField];
    
    // Розница
    $filterRetail = [
        'CATEGORY_ID' => $catRetail,
        'STAGE_ID' => $wonStages['retail'],
        '!' . $orderField => false,  // НЕ пустое
        $shippingDocField => false,  // пустое
    ];
    $dbRetail = \CCrmDeal::GetList(['DATE_MODIFY' => 'ASC'], $filterRetail, $selectFields, false, ['nTopCount' => $config['batch_size'] ?? 20]);
    while ($deal = $dbRetail->Fetch()) {
        // Дублирующая проверка на уровне PHP
        if (!empty($deal[$orderField]) && empty($deal[$shippingDocField])) {
            $deals[] = $deal;
        }
    }
    
    // Опт
    $filterWholesale = [
        'CATEGORY_ID' => $catWholesale,
        'STAGE_ID' => $wonStages['wholesale'],
        '!' . $orderField => false,
        $shippingDocField => false,
    ];
    $dbWholesale = \CCrmDeal::GetList(['DATE_MODIFY' => 'ASC'], $filterWholesale, $selectFields, false, ['nTopCount' => $config['batch_size'] ?? 20]);
    while ($deal = $dbWholesale->Fetch()) {
        if (!empty($deal[$orderField]) && empty($deal[$shippingDocField])) {
            $deals[] = $deal;
        }
    }
    
    return $deals;
}

function checkExpenseInvoiceIn1C($dealId, $config) {
    $httpClient = new \Bitrix\Main\Web\HttpClient(['socketTimeout'=>30,'streamTimeout'=>30]);
    $authHeader = 'Basic '.base64_encode($config['1c_auth']['login'].':'.$config['1c_auth']['password']);
    $httpClient->setHeader('Authorization', $authHeader);
    $httpClient->setHeader('Accept', 'application/json');
    
    $url = rtrim($config['1c_base_url'], '/').'/ExpInvoice';
    $response = $httpClient->get($url);
    if ($httpClient->getStatus() !== 200) return false;
    
    $invoices = @json_decode($response, true);
    if (!is_array($invoices)) return false;
    
    foreach ($invoices as $inv) {
        if (is_array($inv) && (string)($inv['DNK_id'] ?? '') === (string)$dealId && !empty($inv['Номер'])) {
            return ['Номер'=>$inv['Номер'], 'УИД'=>$inv['УИД']??'', 'Дата'=>$inv['Дата']??'', 'Проведен'=>$inv['Проведен']??'Нет'];
        }
    }
    return false;
}

function createExpenseInvoiceIn1C($dealId, $config) {
    $httpClient = new \Bitrix\Main\Web\HttpClient(['socketTimeout'=>30,'streamTimeout'=>30]);
    $authHeader = 'Basic '.base64_encode($config['1c_auth']['login'].':'.$config['1c_auth']['password']);
    $httpClient->setHeader('Authorization', $authHeader);
    $httpClient->setHeader('Content-Type', 'application/json; charset=utf-8');
    
    $payload = [['id'=>(string)$dealId, 'date'=>date('c'), 'id_custumerOrder'=>(string)$dealId]];
    $url = rtrim($config['1c_base_url'], '/').'/ExpInvoice/';
    $response = $httpClient->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $status = $httpClient->getStatus();
    $body = trim($response);
    
    $found = null;
    if (!empty($body) && ($status === 200 || $status === 201)) {
        $result = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($result)) {
            $doc = isset($result[0]) ? $result[0] : $result;
            if (!empty($doc['Номер'])) $found = $doc;
        }
    }
    
    if (!$found) {
        $maxRetries = 3;
        $delays = [3000000, 4000000, 5000000];
        for ($retry = 0; $retry < $maxRetries && !$found; $retry++) {
            usleep($delays[$retry]);
            $checkResponse = $httpClient->get(rtrim($config['1c_base_url'], '/').'/ExpInvoice');
            $checkDocs = @json_decode($checkResponse, true);
            if (is_array($checkDocs)) {
                foreach ($checkDocs as $doc) {
                    if (is_array($doc) && (string)($doc['DNK_id'] ?? '') === (string)$dealId && !empty($doc['Номер'])) {
                        $found = $doc;
                        break;
                    }
                }
            }
        }
    }
    
    if ($found && !empty($found['Номер'])) {
        return ['Номер'=>$found['Номер'], 'УИД'=>$found['УИД']??'', 'Дата'=>$found['Дата']??'', 'Проведен'=>$found['Проведен']??'Нет'];
    }
    return null;
}

function getShippingDocPdfFrom1C($dnkId, $type, $config) {
    $type = strtolower($type);
    if (!in_array($type, ['upd', 'torg12'])) return false;
    $url = rtrim($config['1c_base_url'], '/')."/orders/{$type}/".urlencode($dnkId);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_USERPWD => $config['1c_auth']['login'].':'.$config['1c_auth']['password'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/pdf', 'Expect:'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $pdf = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    $isPdf = ($code === 200) && (stripos($ctype, 'pdf') !== false || stripos($ctype, 'octet-stream') !== false || stripos($pdf, '%PDF-') === 0 || strlen($pdf) > 100);
    return $isPdf ? $pdf : false;
}

function uploadPdfToTimeline($dealId, $filename, $pdfContent, $config) {
    try {
        $base64 = base64_encode($pdfContent);
        $webhook = rtrim($config['b24_webhook_url'], '/').'/crm.timeline.comment.add.json';
        $fields = ['fields'=>['ENTITY_ID'=>(int)$dealId, 'ENTITY_TYPE'=>'deal', 'COMMENT'=>"Расходная накладная {$filename}", 'FILES'=>[[$filename, $base64]]]];
        $curl = curl_init($webhook);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($fields), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
        $resp = curl_exec($curl); curl_close($curl);
        $result = json_decode($resp, true);
        return ($result && !empty($result['result']));
    } catch (\Exception $e) { Logger::error('Ошибка загрузки файла', ['deal_id'=>$dealId, 'error'=>$e->getMessage()]); return false; }
}

function createTimelineEntry($dealId, $comment, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/').'/crm.timeline.comment.add.json';
        $fields = ['fields'=>['ENTITY_ID'=>(int)$dealId, 'ENTITY_TYPE'=>'deal', 'COMMENT'=>$comment]];
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($fields), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
        $response = curl_exec($curl); curl_close($curl);
        $result = json_decode($response, true);
        return ($result && !empty($result['result'])) ? $result['result'] : false;
    } catch (\Exception $e) { Logger::error('Ошибка таймлайна', ['deal_id'=>$dealId, 'error'=>$e->getMessage()]); return false; }
}

function updateDealViaRest($dealId, $fields, $config) {
    try {
        $webhookUrl = rtrim($config['b24_webhook_url'], '/').'/crm.deal.update.json';
        $postData = http_build_query(['id'=>(int)$dealId, 'fields'=>$fields]);
        $curl = curl_init($webhookUrl);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$postData, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true]);
        $response = curl_exec($curl); $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $result = json_decode($response, true);
        return ($httpCode === 200 && !empty($result['result']));
    } catch (\Exception $e) { Logger::error('Ошибка обновления сделки', ['deal_id'=>$dealId, 'error'=>$e->getMessage()]); return false; }
}

/**
 * Загрузка нескольких PDF в таймлайн в ОДНОЙ записи
 * @param int $dealId ID сделки
 * @param string $comment Текст комментария
 * @param array $docs Массив файлов: ['filename'=>..., 'content'=>...]
 * @param array $config Конфигурация
 * @return bool
 */
function uploadPdfsToTimelineCombined($dealId, $comment, $docs, $config) {
    try {
        $webhook = rtrim($config['b24_webhook_url'], '/').'/crm.timeline.comment.add.json';
        
        // Формируем массив файлов: [ [имя, base64], [имя, base64], ... ]
        $filesArray = [];
        foreach ($docs as $doc) {
            $filesArray[] = [$doc['filename'], base64_encode($doc['content'])];
        }
        
        $fields = [
            'fields' => [
                'ENTITY_ID' => (int)$dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment,
                'FILES' => $filesArray
            ]
        ];
        
        $curl = curl_init($webhook);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $resp = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($resp, true);
        $success = ($httpCode === 200 && !empty($result['result']));
        
        if ($success) {
            Logger::info('Файлы загружены в таймлайн одной записью', [
                'deal_id' => $dealId,
                'files' => array_keys($docs)
            ]);
        } else {
            Logger::error('Ошибка загрузки файлов в таймлайн', [
                'deal_id' => $dealId,
                'http_code' => $httpCode,
                'response' => $result
            ]);
        }
        
        return $success;
    } catch (\Exception $e) {
        Logger::error('Исключение при загрузке файлов', [
            'deal_id' => $dealId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

function outputResult($processed, $created, $failed, $total, $failures) {
    $result = ['success'=>$failed===0, 'processed'=>$processed, 'created'=>$created, 'failed'=>$failed, 'total'=>$total, 'failures'=>$failures, 'timestamp'=>date('Y-m-d H:i:s')];
    if (PHP_SAPI === 'cli') { echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL; }
    else { header('Content-Type: application/json; charset=utf-8'); echo json_encode($result, JSON_UNESCAPED_UNICODE); }
}
?>