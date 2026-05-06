<?php
/**
 * 🧪 Диагностика подключения к 1С
 * Запуск: php /local/1c_integration/test_1c_connection.php
 * Или через браузер: https://ваш-сайт/local/1c_integration/test_1c_connection.php
 */

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// === ЗАГРУЗКА КОНФИГА ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");

// === НАСТРОЙКИ ===
$auth = $config['1c_auth'] ?? [];
$login = $auth['login'] ?? '';
$password = $auth['password'] ?? '';
$baseUrl = rtrim($config['1c_base_url'] ?? '', '/');

// === ЦВЕТНОЙ ВЫВОД ДЛЯ CLI ===
$isCli = PHP_SAPI === 'cli';
function out($msg, $type = 'info', $cli = null) {
    global $isCli;
    $useCli = $cli !== null ? $cli : $isCli;
    
    $colors = [
        'info' => "\033[36m",    // cyan
        'success' => "\033[32m", // green
        'warning' => "\033[33m", // yellow
        'error' => "\033[31m",   // red
        'reset' => "\033[0m"
    ];
    
    $prefix = [
        'info' => '[ℹ]',
        'success' => '[✅]',
        'warning' => '[⚠]',
        'error' => '[❌]'
    ][$type] ?? '[?]';
    
    if ($useCli) {
        echo $colors[$type] . $prefix . ' ' . $msg . $colors['reset'] . PHP_EOL;
    } else {
        $htmlColors = [
            'info' => '#17a2b8',
            'success' => '#28a745',
            'warning' => '#ffc107',
            'error' => '#dc3545'
        ];
        echo "<div style='color:{$htmlColors[$type]};font-family:monospace;'>{$prefix} {$msg}</div>\n";
    }
}

out("=== 🧪 ДИАГНОСТИКА ПОДКЛЮЧЕНИЯ К 1С ===", 'info');
out("Base URL: {$baseUrl}", 'info');
out("Login: " . ($login ? substr($login, 0, 3) . '***' : 'НЕ ЗАДАН'), 'info');
out("Password: " . ($password ? '***' : 'НЕ ЗАДАН'), 'info');
out("", 'info');

// === ТЕСТ 1: Доступность хоста ===
out("🔹 Тест 1: Проверка доступности хоста...", 'info');
$host = parse_url($baseUrl, PHP_URL_HOST);
$port = parse_url($baseUrl, PHP_URL_PORT) ?? 80;

if ($host) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($fp) {
        out("✅ Хост {$host}:{$port} доступен", 'success');
        fclose($fp);
    } else {
        out("❌ Не удалось подключиться к {$host}:{$port} ({$errstr})", 'error');
        exit(1);
    }
} else {
    out("❌ Не удалось определить хост из URL", 'error');
    exit(1);
}
out("", 'info');

// === ТЕСТ 2: Базовая аутентификация (корень обмена) ===
out("🔹 Тест 2: Проверка базовой аутентификации...", 'info');

$testUrl = $baseUrl . '/';  // Пробуем корень или известный эндпоинт
$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "{$login}:{$password}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_NOBODY => true,  // Только заголовки
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

out("URL: {$testUrl}", 'info');
out("HTTP-код: {$httpCode}", $httpCode == 401 ? 'error' : ($httpCode >= 200 && $httpCode < 400 ? 'success' : 'warning'));

if ($httpCode == 401) {
    out("❌ Ошибка аутентификации: неверный логин или пароль", 'error');
    out("💡 Проверьте учётные данные в 1С: Администрирование → Пользователи", 'warning');
} elseif ($httpCode == 404) {
    out("⚠️ Эндпоинт не найден (404) — возможно, неверный путь или сервис не опубликован", 'warning');
} elseif ($httpCode >= 200 && $httpCode < 300) {
    out("✅ Аутентификация успешна", 'success');
} elseif ($httpCode == 0) {
    out("❌ Таймаут или ошибка сети: {$error}", 'error');
} else {
    out("⚠️ Неожиданный ответ: {$httpCode}", 'warning');
}
out("", 'info');

// === ТЕСТ 3: Проверка известных эндпоинтов ===
$endpoints = [
    '/items/0/0' => 'Получение изменённых товаров',
    '/Users' => 'Пользователи (ошибка в вашем скрипте)',
    '/users' => 'Пользователи (правильный путь?)',
    '/group' => 'Группы доступа',
    '/accept/' => 'Подтверждение обработки',
];

out("🔹 Тест 3: Проверка доступных эндпоинтов...", 'info');

foreach ($endpoints as $endpoint => $description) {
    $url = rtrim($baseUrl, '/') . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "{$login}:{$password}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $status = match(true) {
        $httpCode >= 200 && $httpCode < 300 => ['✅', 'success'],
        $httpCode == 401 => ['🔐', 'warning'],
        $httpCode == 404 => ['❓', 'warning'],
        $httpCode >= 400 => ['❌', 'error'],
        default => ['⏱', 'warning']
    };
    
    out("{$status[0]} {$endpoint} — {$description} (HTTP {$httpCode})", $status[1]);
}
out("", 'info');

// === ТЕСТ 4: Проверка структуры ответа /items/0/0 ===
out("🔹 Тест 4: Проверка ответа /items/0/0 (если доступен)...", 'info');

$url = rtrim($baseUrl, '/') . '/items/0/0';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "{$login}:{$password}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        out("✅ Ответ валидный JSON, элементов: " . count($data), 'success');
        
        if (!empty($data[0])) {
            out("Пример полей первого элемента:", 'info');
            foreach (array_slice(array_keys($data[0]), 0, 8) as $key) {
                $val = $data[0][$key];
                $preview = is_string($val) && strlen($val) > 40 ? substr($val, 0, 40) . '...' : $val;
                out("  • {$key}: {$preview}", 'info');
            }
            
            // 🔍 Проверяем наличие ключевых полей
            $required = ['ИДЗаписи', 'УИДНоменклатуры', 'Код', 'Наименование'];
            $missing = array_filter($required, fn($f) => !array_key_exists($f, $data[0]));
            
            if (empty($missing)) {
                out("✅ Все ключевые поля присутствуют", 'success');
            } else {
                out("⚠️ Отсутствуют поля: " . implode(', ', $missing), 'warning');
            }
        }
    } else {
        out("⚠️ Ответ не является валидным JSON: " . json_last_error_msg(), 'warning');
        out("Первые 200 символов: " . substr($response, 0, 200), 'info');
    }
} else {
    out("⚠️ Не удалось получить данные (HTTP {$httpCode})", 'warning');
}
out("", 'info');

// === ТЕСТ 5: Проверка прав пользователя ===
out("🔹 Тест 5: Проверка прав пользователя в 1С...", 'info');

// Пробуем создать тестовый запрос на запись (без реального создания)
$testPayload = json_encode([['guid' => 'test-' . uniqid()]], JSON_UNESCAPED_UNICODE);
$url = rtrim($baseUrl, '/') . '/accept/';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $testPayload,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => "{$login}:{$password}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'Expect:',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    out("✅ Пользователь имеет права на запись в /accept/", 'success');
} elseif ($httpCode == 401) {
    out("❌ Ошибка аутентификации при записи", 'error');
} elseif ($httpCode == 403) {
    out("❌ Доступ запрещён (403) — проверьте права пользователя в 1С", 'error');
    out("💡 В 1С: Конфигуратор → Пользователи → [ваш пользователь] → Права → HTTP-сервисы", 'warning');
} elseif ($httpCode == 404) {
    out("⚠️ Эндпоинт /accept/ не найден — возможно, неверный путь", 'warning');
} elseif ($httpCode == 500) {
    out("⚠️ Внутренняя ошибка 1С (500) — проверьте логи 1С", 'warning');
    out("Ответ: " . trim(substr($response, 0, 200)), 'info');
} else {
    out("⚠️ Неожиданный ответ: {$httpCode}", 'warning');
}
out("", 'info');

// === ИТОГИ ===
out("=== 📋 РЕКОМЕНДАЦИИ ===", 'info');

if ($httpCode == 404) {
    out("🔧 Вероятная причина: неверный путь к эндпоинту", 'warning');
    out("• Проверьте, опубликован ли HTTP-сервис в 1С", 'info');
    out("• Уточните точный путь: возможно, /users (с маленькой буквы) вместо /Users", 'info');
    out("• Проверьте в 1С: Администрирование → Интеграция → Обмен данными → Настройки HTTP-сервисов", 'info');
}

if ($httpCode == 401) {
    out("🔧 Вероятная причина: неверные учётные данные", 'warning');
    out("• Проверьте логин/пароль в 1С: Администрирование → Пользователи", 'info');
    out("• Убедитесь, что у пользователя есть право на вызов HTTP-сервиса", 'info');
}

out("🔧 Для отладки включите логирование в 1С:", 'info');
out("• Конфигуратор → Администрирование → Журнал регистрации → Уровень: Подробный", 'info');
out("• После теста проверьте журнал на предмет ошибок обработки запроса", 'info');

out("", 'info');
out("=== ✅ ДИАГНОСТИКА ЗАВЕРШЕНА ===", 'success');

// === ВЫВОД ДЛЯ WEB ===
if (!$isCli) {
    echo "<hr><small style='color:#666;'>Запущен: " . date('Y-m-d H:i:s') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "</small>";
}