<?php
/**
 * Синхронизация пользователей Битрикс24 → 1С
 * 
 * Логика:
 * - Если пользователь есть в Б24, но отсутствует в 1С → создать в 1С
 * - Ключ сопоставления: EMAIL (приоритет) или ФИО (NAME + LAST_NAME)
 * - Поле связи: DNK_id в 1С хранит ID пользователя из Б24
 * 
 * Запуск: по cron раз в сутки или по событию
 */

// ФИКС ДЛЯ CLI: определяем DOCUMENT_ROOT ДО любого require
if (PHP_SAPI === 'cli' || empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/cli_bootstrap.php");

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

\Bitrix\Main\Loader::includeModule('main');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

set_time_limit(1800); // 30 минут
ini_set('memory_limit', '512M');

// === ЗАГРУЗКА КОНФИГУРАЦИИ ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");
Logger::init($config);
$oneC = new OneCClient($config);

Logger::info('=== ЗАПУСК СИНХРОНИЗАЦИИ ПОЛЬЗОВАТЕЛЕЙ (Б24 → 1С) ===');

// === ПОЛУЧЕНИЕ ПОЛЬЗОВАТЕЛЕЙ ИЗ БИТРИКС24 ===
$b24Users = getBitrix24Users($config);
Logger::info('Пользователи получены из Битрикс24', ['count' => count($b24Users)]);

if (empty($b24Users)) {
    Logger::warning('Список пользователей Битрикс24 пуст');
    exit;
}

// === ПОЛУЧЕНИЕ ПОЛЬЗОВАТЕЛЕЙ ИЗ 1С ===
$users1C = $oneC->getAllUsers(); // GET /Users/

if ($users1C === false || !is_array($users1C)) {
    Logger::error('Не удалось получить пользователей из 1С');
    exit;
}

// === ПОЛУЧЕНИЕ ГРУПП ИЗ 1С ===
$availableGroups = get1CGroups($oneC);

Logger::info('Пользователи получены из 1С', ['count' => count($users1C)]);

// === ПОСТРОЕНИЕ ИНДЕКСОВ 1С: по DNK_id, email и ФИО ===
$index1CByDnkId = [];      // Приоритет №1: уникальный ID Б24
$index1CByEmail = [];      // Приоритет №2: уникальный email
$index1CByName = [];       // Приоритет №3: ФИО (резерв)

foreach ($users1C as $user) {
    // Индекс по DNK_id
    $dnkId = $user['DNK_id'] ?? '';
    if (!empty($dnkId)) {
        $index1CByDnkId[$dnkId] = $user;
    }

    // Индекс по email (нормализуем: нижний регистр + trim)
    $email1C = trim(strtolower($user['АдресЭлектроннойПочты'] ?? ''));
    if (!empty($email1C)) {
        $index1CByEmail[$email1C] = $user;
    }
    
    // Индекс по ФИО (резерв)
    $fullName1C = $user['Наименование'] ?? '';
    if (!empty($fullName1C)) {
        $parts = preg_split('/\s+/u', trim($fullName1C), 2);
        $lastName1C = $parts[0] ?? '';
        $firstName1C = $parts[1] ?? '';
        $nameKey = normalizeFullName($lastName1C, $firstName1C);
        
        if (!empty($nameKey)) {
            $index1CByName[$nameKey] = $user;
        }
    }
}

Logger::debug('Индексы 1С построены', [
    'by_dnk_id' => count($index1CByDnkId),
    'by_email' => count($index1CByEmail),
    'by_name' => count($index1CByName)
]);

// === ОБРАБОТКА: ПОИСК И СОЗДАНИЕ ОТСУТСТВУЮЩИХ ПОЛЬЗОВАТЕЛЕЙ ===
$processed = 0;
$created = 0;
$skipped = 0;
$errors = 0;
$details = [];

foreach ($b24Users as $b24User) {
    $processed++;
    
    $b24Id = $b24User['ID'] ?? '';
    $email = trim(strtolower($b24User['EMAIL'] ?? ''));
    $firstName = $b24User['NAME'] ?? '';
    $lastName = $b24User['LAST_NAME'] ?? '';
    $secondName = $b24User['SECOND_NAME'] ?? '';
    $isActive = ($b24User['ACTIVE'] ?? true) === true;
    
    Logger::debug('Поиск пользователя', [
        'b24_id' => $b24Id,
        'email' => $email,
        'original' => trim("{$lastName} {$firstName} {$secondName}")
    ]);
    
    // 🔹 Поиск в 1С: 3 уровня приоритета
    $existing1C = null;
    $matchedBy = '';
    
    // 1️⃣ Приоритет №1: поиск по DNK_id
    if (!empty($b24Id) && isset($index1CByDnkId[$b24Id])) {
        $existing1C = $index1CByDnkId[$b24Id];
        $matchedBy = 'DNK_id';
        Logger::debug('Найден в 1С по DNK_id', [
            'b24_id' => $b24Id,
            '1c_name' => $existing1C['Наименование'] ?? '',
            '1c_uid' => $existing1C['УИД'] ?? ''
        ]);
    }
    // 2️⃣ Приоритет №2: поиск по email
    elseif (!empty($email) && isset($index1CByEmail[$email])) {
        $existing1C = $index1CByEmail[$email];
        $matchedBy = 'email';
        Logger::debug('Найден в 1С по email', [
            'b24_id' => $b24Id,
            'email' => $email,
            '1c_name' => $existing1C['Наименование'] ?? '',
            '1c_uid' => $existing1C['УИД'] ?? ''
        ]);
    }
    // 3️⃣ Приоритет №3: поиск по ФИО (резерв)
    else {
        $b24NameKey = normalizeFullName($lastName, $firstName, $secondName);
        if (!empty($b24NameKey) && isset($index1CByName[$b24NameKey])) {
            $existing1C = $index1CByName[$b24NameKey];
            $matchedBy = 'name';
            Logger::debug('Найден в 1С по ФИО', [
                'b24_id' => $b24Id,
                'name_key' => $b24NameKey,
                '1c_name' => $existing1C['Наименование'] ?? '',
                '1c_uid' => $existing1C['УИД'] ?? ''
            ]);
        }
    }
    
    // Если пользователь найден — пропускаем создание
    if ($existing1C) {
        $skipped++;
        Logger::info('Пользователь уже есть в 1С', [
            'b24_id' => $b24Id,
            'matched_by' => $matchedBy,
            '1c_name' => $existing1C['Наименование'] ?? '',
            '1c_uid' => $existing1C['УИД'] ?? ''
        ]);
        continue;
    }
    
    // 🔹 Пользователь не найден — создаём нового
    Logger::info('Пользователь не найден в 1С, создаём', ['b24_id' => $b24Id]);
    
    $usersData = prepareUserDataFor1C($b24User, $config, $availableGroups);
    $result = $oneC->createUser($usersData);

    if ($result['success']) {
        $created++;
        $details[] = [
            'b24_id' => $b24Id,
            'name' => trim("{$lastName} {$firstName}"),
            'email' => $email,
            '1c_uid' => $result['uid'] ?? '',
            'status' => 'created'
        ];
        Logger::info('Пользователь создан в 1С', [
            'b24_id' => $b24Id,
            '1c_uid' => $result['uid'] ?? '',
            'name' => $usersData[0]['name'] ?? 'unknown'
        ]);
    } else {
        $errors++;
        $details[] = [
            'b24_id' => $b24Id,
            'name' => trim("{$lastName} {$firstName}"),
            'email' => $email,
            'error' => $result['error'] ?? 'Неизвестная ошибка',
            'status' => 'error'
        ];
        Logger::error('Ошибка создания пользователя в 1С', [
            'b24_id' => $b24Id,
            'error' => $result['error'] ?? 'Неизвестная ошибка'
        ]);
    }
    
    // Пауза между запросами
    usleep(100000); // 100 мс
}

// === ИТОГИ ===
Logger::info('=== ЗАВЕРШЕНИЕ СИНХРОНИЗАЦИИ ПОЛЬЗОВАТЕЛЕЙ ===', [
    'processed' => $processed,
    'created' => $created,
    'skipped' => $skipped,
    'errors' => $errors
]);

// Вывод результата (для cron / web)
$result = [
    'success' => $errors === 0,
    'processed' => $processed,
    'created' => $created,
    'skipped' => $skipped,
    'errors' => $errors,
    'details' => $details,
    'timestamp' => date('Y-m-d H:i:s')
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

// ===== ФУНКЦИИ =====

/**
 * Получение списка пользователей из Битрикс24
 */
function getBitrix24Users($config) {
    $users = [];
    
    // Используем REST API Битрикс24
    $webhookUrl = rtrim($config['b24_webhook_url'], '/') . '/user.search.json';
    
    // Получаем только сотрудников (USER_TYPE = employee), активных
    $fields = [
        'filter' => [
            'USER_TYPE' => 'employee',
            // 'ACTIVE' => true // можно добавить, если нужно только активных
        ],
        'select' => [
            'ID', 'XML_ID', 'ACTIVE', 'NAME', 'LAST_NAME', 'SECOND_NAME',
            'EMAIL', 'LOGIN', 'PERSONAL_PHONE', 'WORK_PHONE', 'WORK_POSITION',
            'UF_DEPARTMENT', 'UF_EMPLOYMENT_DATE', 'DATE_REGISTER'
        ],
        'start' => 0
    ];
    
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
    
    if ($httpCode !== 200) {
        Logger::error('Ошибка запроса к Битрикс24', ['http_code' => $httpCode]);
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (!empty($data['result']) && is_array($data['result'])) {
        $users = $data['result'];
    }
    
    // Обработка пагинации (если пользователей много)
    while (!empty($data['next']) && count($users) < ($config['user_sync_limit'] ?? 1000)) {
        $curl = curl_init($data['next']);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data['result'])) {
                $users = array_merge($users, $data['result']);
            }
        } else {
            break;
        }
    }
    
    return $users;
}

/**
 * Подготовка данных пользователя для создания в 1С
 * Формат: массив объектов, как требует эндпоинт /Users/
 */
function prepareUserDataFor1C($b24User, $config, $availableGroups = []) {
    $firstName = $b24User['NAME'] ?? '';
    $lastName = $b24User['LAST_NAME'] ?? '';
    $secondName = $b24User['SECOND_NAME'] ?? '';
    $email = $b24User['EMAIL'] ?? '';
    $b24Id = $b24User['ID'] ?? '';
    $isActive = ($b24User['ACTIVE'] ?? true) === true;
    
    // Формируем name в формате 1С: "Фамилия Имя"
    $fullName = trim("{$lastName} {$firstName}");
    if (empty($fullName)) {
        $fullName = "Пользователь_{$b24Id}";
    }
    
    // Логин: очищаем от спецсимволов, делаем уникальным
    $login = !empty($email) 
        ? preg_replace('/[^a-zA-Z0-9_\.\-@]/u', '', explode('@', $email)[0])
        : preg_replace('/[^a-zA-Z0-9_]/u', '', mb_strtolower($firstName . $lastName, 'UTF-8'));
    
    if (empty($login)) {
        $login = "user_{$b24Id}";
    }
    
    // Группы доступа
    $groups = getUser1CGroups($b24User, $config, $availableGroups);
    
    // Формируем ОДИН объект пользователя (в формате 1С)
    $userObject = [
        'id' => (string)$b24Id,           // ID из Б24 для связи
        'name' => $fullName,               // ФИО для отображения
        'login' => $login,                 // Логин для входа
        'email' => $email,                 // Email (уникален в 1С)
        'group' => $groups,                // Массив групп: [{'guid'=>'...'}, ...]
    ];
    
    // Возвращаем МАССИВ из одного объекта
    return [$userObject];
}

/**
 * Нормализация ФИО для надёжного сравнения
 * - Приводит к нижнему регистру
 * - Убирает лишние пробелы, спецсимволы (*, _, ., и т.д.)
 * - Возвращает формат: "фамилия имя" (без отчества)
 */
function normalizeFullName($lastName, $firstName, $secondName = '') {
    // Склеиваем: Фамилия + Имя (отчество игнорируем для надёжности)
    $name = trim("{$lastName} {$firstName}");
    
    // Приводим к нижнему регистру с поддержкой кириллицы
    $name = mb_strtolower($name, 'UTF-8');
    
    // Убираем спецсимволы, которые могут мешать сравнению
    $name = preg_replace('/[\*\_\.\,\;\:\(\)\"\'\/\\\\]+/u', '', $name);
    
    // Заменяем множественные пробелы на один
    $name = preg_replace('/\s+/u', ' ', $name);
    
    return trim($name);
}

/**
 * Получение списка групп доступа из 1С
 * @param OneCClient $oneC Клиент 1С
 * @return array Массив групп [['УИД' => '...', 'Наименование' => '...'], ...]
 */
function get1CGroups($oneC) {
    try {
        $groups = $oneC->getAllGroups(); // GET /group/
        
        if (!is_array($groups)) {
            Logger::warning('Не удалось получить группы из 1С');
            return [];
        }
        
        // Фильтруем: оставляем только активные группы (без "(не используется)")
        $activeGroups = array_filter($groups, function($g) {
            $name = $g['Наименование'] ?? '';
            return strpos($name, '(не используется)') !== 0;
        });
        
        Logger::debug('Группы 1С загружены', [
            'total' => count($groups),
            'active' => count($activeGroups)
        ]);
        
        return array_values($activeGroups);
        
    } catch (\Exception $e) {
        Logger::error('Ошибка получения групп 1С', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Определение групп 1С для пользователя на основе данных Битрикс24
 */
function getUser1CGroups($b24User, $config, $availableGroups) {
    $groups = [];
    $groupMap = $config['user_group_map'] ?? [];
    $defaultGroup = $config['user_default_group'] ?? null;
    
    // Получаем список валидных УИД групп из 1С
    $validGroupUids = array_column($availableGroups, 'УИД');
    
    // Поиск по должности (WORK_POSITION)
    $position = trim($b24User['WORK_POSITION'] ?? '');
    if (!empty($position) && isset($groupMap[$position])) {
        $guid = $groupMap[$position];
        // Добавляем только если группа существует в 1С
        if (in_array($guid, $validGroupUids)) {
            $groups[] = ['guid' => $guid];
        } else {
            Logger::warning('Группа не найдена в 1С, пропущена', [
                'position' => $position,
                'group_guid' => $guid
            ]);
        }
    }
    
    // Поиск по отделу (UF_DEPARTMENT)
    $departments = $b24User['UF_DEPARTMENT'] ?? [];
    if (!empty($departments) && is_array($departments)) {
        foreach ($departments as $deptId) {
            $guid = $groupMap[(string)$deptId] ?? null;
            if ($guid && in_array($guid, $validGroupUids)) {
                $groups[] = ['guid' => $guid];
            }
        }
    }
    
    // Если ничего не найдено — группа по умолчанию (если валидна)
    if (empty($groups) && $defaultGroup && in_array($defaultGroup, $validGroupUids)) {
        $groups[] = ['guid' => $defaultGroup];
    } elseif (empty($groups) && $defaultGroup) {
        Logger::warning('Группа по умолчанию не найдена в 1С', [
            'default_group' => $defaultGroup
        ]);
    }
    
    // Убираем дубликаты групп
    $uniqueGuids = array_unique(array_column($groups, 'guid'));
    $groups = array_map(fn($guid) => ['guid' => $guid], $uniqueGuids);
    
    Logger::debug('Определены группы 1С для пользователя', [
        'b24_id' => $b24User['ID'] ?? '',
        'position' => $position,
        'departments' => $departments,
        'groups_count' => count($groups),
        'groups' => array_column($groups, 'guid')
    ]);
    
    return $groups;
}
?>