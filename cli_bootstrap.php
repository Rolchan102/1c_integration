<?php
/**
 * Bootstrap для CLI/cron — без веб-логики и вывода
 * /local/1c_integration/cli_bootstrap.php
 */

// 1. Определяем DOCUMENT_ROOT для CLI
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
    $_SERVER["HTTP_HOST"] = $_SERVER["HTTP_HOST"] ?? 'crm.svetpremium.ru';
    $_SERVER["SERVER_NAME"] = $_SERVER["SERVER_NAME"] ?? 'crm.svetpremium.ru';
}

if (PHP_SAPI === 'cli' && !empty($_SERVER["DOCUMENT_ROOT"])) {
    chdir($_SERVER["DOCUMENT_ROOT"]);  // ← Гарантирует правильную рабочую директорию
}

// 2. Запускаем буферизацию ДО подключения prolog
if (PHP_SAPI === 'cli') {
    ob_start(function() { return ''; });
}

// 3. Отключаем статистику и проверки прав
if (!defined('NO_KEEP_STATISTIC')) define('NO_KEEP_STATISTIC', 'Y');
if (!defined('NOT_CHECK_PERMISSIONS')) define('NOT_CHECK_PERMISSIONS', true);
if (!defined('DisableEventsCheck')) define('DisableEventsCheck', true);

// 4. Подключаем ядро
$prolog = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
if (!file_exists($prolog)) {
    fwrite(STDERR, "[CRON ERROR] prolog_before.php not found: $prolog\n");
    exit(1);
}
require_once($prolog);

// 5. Очищаем буфер
if (PHP_SAPI === 'cli') {
    if (ob_get_level()) {
        ob_end_clean();
    }
}

// 6. Проверка загрузки модулей
if (!class_exists('\Bitrix\Main\Loader')) {
    fwrite(STDERR, "[CRON ERROR] Main module not loaded\n");
    exit(1);
}