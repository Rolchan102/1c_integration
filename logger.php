<?php
namespace Integration;

class Logger {
    private static $logFile;
    
    public static function init($config) {
        self::$logFile = $config['log_file'];
        $logDir = dirname(self::$logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }
    
    public static function debug($message, $context = []) {
        self::log('DEBUG', $message, $context);
    }
    
    private static function log($level, $message, $context) {
        if (!self::$logFile) return;
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $message, $contextStr);
        file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}