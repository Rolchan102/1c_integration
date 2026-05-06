<?php
/**
 * Вспомогательные функции для поиска УИД документов в 1С
 * Можно подключать в тестовых скриптах без выполнения основной логики
 */

namespace Integration;

/**
 * Получение УИД счета на оплату из 1С по номеру и DNK_id
 */
function getInvoiceUidFrom1C($dealId, $invoiceNumber, $config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $login = $config['1c_auth']['login'] ?? '';
        $password = $config['1c_auth']['password'] ?? '';
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding("{$login}:{$password}", 'UTF-8', 'UTF-8')
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/json');
        
        $baseUrl = rtrim($config['1c_base_url'], '/');
        $url = "{$baseUrl}/orders/0/{$dealId}";
        
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();
        
        if ($statusCode !== 200) {
            return false;
        }
        
        $invoices = @json_decode($response, true);
        if (!is_array($invoices)) {
            return false;
        }
        
        $normalize = function($s) {
            return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', trim($s)));
        };
        $expectedNorm = $normalize($invoiceNumber);
        
        foreach ($invoices as $inv) {
            $invNum = $inv['Номер'] ?? $inv['НомерСчета'] ?? '';
            $invUid = $inv['УИД'] ?? $inv['УИДСчета'] ?? '';
            
            if ($normalize($invNum) === $expectedNorm) {
                return $invUid;
            }
        }
        
        return false;
        
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Получение УИД заказа покупателя из 1С по номеру и DNK_id
 */
function getOrderUidFrom1C($dealId, $orderNumber, $config) {
    try {
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30
        ]);
        
        $login = $config['1c_auth']['login'] ?? '';
        $password = $config['1c_auth']['password'] ?? '';
        $authHeader = 'Basic ' . base64_encode(
            mb_convert_encoding("{$login}:{$password}", 'UTF-8', 'UTF-8')
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Accept', 'application/json');
        
        $baseUrl = rtrim($config['1c_base_url'], '/');
        $url = "{$baseUrl}/CustomerOrder";
        
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();
        
        if ($statusCode !== 200) {
            return false;
        }
        
        $orders = @json_decode($response, true);
        if (!is_array($orders)) {
            return false;
        }
        
        $normalize = function($s) {
            return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', trim($s)));
        };
        $expectedNorm = $normalize($orderNumber);
        
        foreach ($orders as $order) {
            $orderNum = $order['Номер'] ?? '';
            $dnkId = $order['DNK_id'] ?? '';
            
            if ($normalize($orderNum) === $expectedNorm && (string)$dnkId === (string)$dealId) {
                // 🔹 Приоритет: ИДЗаписи > УИД
                $identifier = $order['ИДЗаписи'] ?? $order['УИД'] ?? '';
                if (!empty($identifier)) {
                    return $identifier;
                }
            }
        }
        
        return false;
        
    } catch (\Exception $e) {
        return false;
    }
}