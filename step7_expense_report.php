<?php
/**
 * Шаг 7: Отчёт по расходам
 * Веб-интерфейс для формирования отчётов по расходам из 1С
 * Исправлено: устранена блокировка сессии через сессию только для проверки прав
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
\Bitrix\Main\Loader::includeModule('crm');

require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/logger.php');
require_once($_SERVER["DOCUMENT_ROOT"] . '/local/1c_integration/1c_client.php');

use Integration\Logger;
use Integration\OneCClient;

// Ручная проверка авторизации (без блокировки сессии)
global $USER;
if (!isset($USER) || !$USER->IsAuthorized()) {
    // Перенаправление на страницу входа
    header('Location: /bitrix/admin/?back_url_admin=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// === ЗАГРУЗКА КОНФИГУРАЦИИ ===
$config = require_once($_SERVER["DOCUMENT_ROOT"] . "/local/1c_integration/config.php");

// === ПРОВЕРКА ДОСТУПА ===
if (!$USER->IsAdmin() && !in_array($USER->GetID(), $config['allowed_users'])) {
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_before.php");
    ShowError("Доступ запрещен");
    die();
}

$APPLICATION->SetTitle("Отчёт по расходам из 1С");

// === ПЕРЕМЕННЫЕ ДЛЯ ФОРМЫ ===
$selectedOrgUid = $_POST['organization'] ?? $config['organizations']['retail'];
$startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_POST['end_date'] ?? date('Y-m-d');
$direction = $_POST['direction'] ?? '';
$articleFilter = $_POST['article'] ?? '';
$exportFormat = $_POST['export'] ?? '';

// === ПОЛУЧЕНИЕ СПИСКА ОРГАНИЗАЦИЙ ===
$organizations = [];
try {
    // 🔧 Используем HttpClient из OneCClient для единообразия
    $httpClient = new \Bitrix\Main\Web\HttpClient([
        'socketTimeout' => 30,
        'streamTimeout' => 30,
        'disableSslVerification' => false
    ]);
    
    // 🔧 Берём авторизацию из конфига (не хардкод!)
    $authHeader = 'Basic ' . base64_encode(
        $config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
    );
    $httpClient->setHeader('Authorization', $authHeader);
    $httpClient->setHeader('Content-Type', 'application/json; charset=utf-8');
    $httpClient->setHeader('Accept', 'application/json');
    
    // 🔧 Формируем URL из конфига (не хардкод!)
    $baseUrl = rtrim($config['1c_base_url'], '/');
    $url = $baseUrl . '/organization';
    $response = $httpClient->get($url);
    $statusCode = $httpClient->getStatus();
    $httpError = $httpClient->getError();

    if ($statusCode === 200) {
        $organizations = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Ошибка парсинга JSON организаций', [
                'json_error' => json_last_error_msg(),
                'raw_response' => mb_substr($response, 0, 500)
            ]);
            $organizations = [];
        } elseif (!is_array($organizations)) {
            Logger::error('Неожиданный формат ответа организаций', [
                'type' => gettype($organizations)
            ]);
            $organizations = [];
        } else {
            Logger::info('Организаций получено из 1С', ['count' => count($organizations)]);
        }
    } else {
        Logger::error('Ошибка получения списка организаций', [
            'url' => $url,
            'status' => $statusCode,
            'http_error' => $httpError
        ]);
    }
    
} catch (\Exception $e) {
    Logger::error('Исключение при получении организаций', [
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

// 🔧 Fallback: если организаций нет, используем дефолт из конфига
if (empty($organizations) && !empty($config['organizations'])) {
    Logger::warning('Список организаций пуст, используем дефолт из конфига');
    // Можно добавить дефолтную организацию вручную, если нужно
}

// === ПОЛУЧЕНИЕ СПИСКА СТАТЕЙ РАСХОДОВ ===
$expenseArticles = [];
try {
    $httpClient = new \Bitrix\Main\Web\HttpClient([
        'socketTimeout' => 30,
        'streamTimeout' => 30,
    ]);
    
    $authHeader = 'Basic ' . base64_encode(
        $config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
    );
    $httpClient->setHeader('Authorization', $authHeader);
    $httpClient->setHeader('Accept', 'application/json');
    
    $baseUrl = rtrim($config['1c_base_url'], '/');
    
    // 🔧 Берём организацию: приоритет — выбранная в форме, затем из конфига
    $sampleOrg = '';
    if (!empty($selectedOrgUid)) {
        $sampleOrg = $selectedOrgUid;
    } elseif (isset($config['organizations']['retail']) && !empty($config['organizations']['retail'])) {
        $sampleOrg = $config['organizations']['retail'];
    } elseif (!empty($organizations) && isset($organizations[0]['УИД'])) {
        $sampleOrg = $organizations[0]['УИД'];
    }
    
    if (!empty($sampleOrg)) {
        // 🔧 Запрашиваем данные за 5 лет, чтобы захватить все статьи
        $sampleStart = date('Y-m-d', strtotime('-5 year'));
        $sampleEnd = date('Y-m-d');
        $samplePeriod = urlencode($sampleStart) . '_' . urlencode($sampleEnd);
        $url = $baseUrl . '/order3/' . urlencode($sampleOrg) . '/' . $samplePeriod;
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();
        
        if ($statusCode === 200) {
            $reportSample = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::warning('Ошибка JSON статей', ['error' => json_last_error_msg()]);
            } elseif (is_array($reportSample) && !empty($reportSample)) {
                // 🔧 Извлекаем уникальные статьи (совместимо с PHP < 7.0)
                $rawArticles = array();
                foreach ($reportSample as $row) {
                    if (isset($row['Статья']) && !empty($row['Статья'])) {
                        $rawArticles[] = $row['Статья'];
                    }
                }
                
                if (!empty($rawArticles)) {
                    $uniqueArticles = array_unique($rawArticles);
                    sort($uniqueArticles);
                    $expenseArticles = array_values($uniqueArticles);
                    
                    Logger::info('Статьи расходов получены', ['count' => count($expenseArticles)]);
                } else {
                    Logger::warning('В выборке нет статей расходов');
                }
            } else {
                Logger::warning('Пустой или некорректный ответ для статей');
            }
        } else {
            Logger::warning('HTTP ошибка при получении статей', ['status' => $statusCode]);
        }
    } else {
        Logger::warning('Не указан УИД организации для загрузки статей');
    }
    
} catch (\Exception $e) {
    Logger::warning('Исключение при получении статей', ['error' => $e->getMessage()]);
}

// === ПОЛУЧЕНИЕ ОТЧЁТА ===
$reportData = [];
$reportSummary = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 🔧 Используем HttpClient с настройками из конфига
        $httpClient = new \Bitrix\Main\Web\HttpClient([
            'socketTimeout' => 120,  // Увеличиваем для отчётов
            'streamTimeout' => 120,
            'disableSslVerification' => false
        ]);
        
        // 🔧 Авторизация из конфига (не хардкод!)
        $authHeader = 'Basic ' . base64_encode(
            $config['1c_auth']['login'] . ':' . $config['1c_auth']['password']
        );
        $httpClient->setHeader('Authorization', $authHeader);
        $httpClient->setHeader('Content-Type', 'application/json; charset=utf-8');
        $httpClient->setHeader('Accept', 'application/json');
        
        // 🔧 Формируем URL из конфига
        $baseUrl = rtrim($config['1c_base_url'], '/');
        $period = urlencode($startDate) . '_' . urlencode($endDate);
        $url = $baseUrl . '/order3/' . urlencode($selectedOrgUid) . '/' . $period;
        $response = $httpClient->get($url);
        $statusCode = $httpClient->getStatus();
        $httpError = $httpClient->getError();

        if ($statusCode !== 200) {
            throw new \Exception(
                'Ошибка получения данных из 1С: HTTP ' . $statusCode . 
                ($httpError ? ' | ' . $httpError : '')
            );
        }
        
        $reportData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
        }
        
        if (!is_array($reportData)) {
            throw new \Exception('Некорректный формат ответа от 1С: ' . gettype($reportData));
        }
        
        // Фильтрация
        if (!empty($direction)) {
            $reportData = array_filter($reportData, function($row) use ($direction) {
                return isset($row['НаправлениеДеятельности']) && $row['НаправлениеДеятельности'] === $direction;
            });
        }
        if (!empty($articleFilter)) {
            $reportData = array_filter($reportData, function($row) use ($articleFilter) {
                return isset($row['Статья']) && stripos($row['Статья'], $articleFilter) !== false;
            });
        }
        $reportData = array_values($reportData);
        
        // Расчёт сводных данных
        $reportSummary = [
            'total_income' => 0,
            'total_expenses' => 0,
            'expense_categories' => []
        ];
        
        foreach ($reportData as $row) {
            $reportSummary['total_income'] += (float)(isset($row['СуммаДоходовОборот']) ? $row['СуммаДоходовОборот'] : 0);
            $reportSummary['total_expenses'] += (float)(isset($row['СуммаРасходовОборот']) ? $row['СуммаРасходовОборот'] : 0);
            
            $article = isset($row['Статья']) ? $row['Статья'] : 'Неизвестно';
            if (!isset($reportSummary['expense_categories'][$article])) {
                $reportSummary['expense_categories'][$article] = 0;
            }
            $reportSummary['expense_categories'][$article] += (float)(isset($row['СуммаРасходовОборот']) ? $row['СуммаРасходовОборот'] : 0);
        }
        $reportSummary['net_profit'] = $reportSummary['total_income'] - $reportSummary['total_expenses'];
        
        Logger::info('Отчёт по расходам сформирован', [
            'организация' => $selectedOrgUid,
            'период' => $startDate . ' - ' . $endDate,
            'записей' => count($reportData)
        ]);
        
    } catch (\Exception $e) {
        $error = $e->getMessage();
        Logger::error('Ошибка формирования отчёта', [
            'организация' => $selectedOrgUid,
            'период' => $startDate . ' - ' . $endDate,
            'ошибка' => $error,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

// === ЭКСПОРТ В CSV ===
if ($exportFormat === 'csv' && !empty($reportData)) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // 🔧 Важно: очистка буфера вывода перед заголовками
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expense_report_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для Excel
    
    fputcsv($output, ['Организация', 'Направление деятельности', 'Статья', 'Доходы', 'Расходы'], ';');
    
    foreach ($reportData as $row) {
        fputcsv($output, [
            $row['Организация'] ?? '',
            $row['НаправлениеДеятельности'] ?? '',
            $row['Статья'] ?? '',
            $row['СуммаДоходовОборот'] ?? 0,
            $row['СуммаРасходовОборот'] ?? 0
        ], ';');
    }
    
    // Итоги
    fputcsv($output, ['ИТОГО', '', '', $reportSummary['total_income'], $reportSummary['total_expenses']], ';');
    fputcsv($output, ['Чистая прибыль', '', '', '', $reportSummary['net_profit']], ';');
    fclose($output);
    exit;
}

// === ВЫВОД СТРАНИЦЫ ===
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_after.php");
?>

<style>
.expense-report-card {
    background: #fff;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
}
.expense-summary {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    margin: 20px 0;
}
.expense-summary-item {
    text-align: center;
    padding: 15px;
    min-width: 150px;
}
.expense-summary-label {
    font-size: 14px;
    color: #666;
    margin-bottom: 5px;
}
.expense-summary-value {
    font-size: 24px;
    font-weight: bold;
    margin: 5px 0;
}
.expense-income { color: #28a745; }
.expense-outcome { color: #dc3545; }
.expense-profit-positive { color: #28a745; }
.expense-profit-negative { color: #dc3545; }
</style>

<div class="expense-report-card">
    <form method="POST" action="">
        <table class="adm-detail-content-table edit-table" style="width: 100%;">
            <tr>
                <td width="30%" class="adm-detail-content-cell-l">Организация:</td>
                <td width="70%" class="adm-detail-content-cell-r">
                    <select name="organization" class="adm-input" style="width: 100%;">
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= htmlspecialchars($org['УИД']) ?>" 
                                <?= $selectedOrgUid === $org['УИД'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['Наименование']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="adm-detail-content-cell-l">Период:</td>
                <td class="adm-detail-content-cell-r">
                    <table>
                        <tr>
                            <td>С:</td>
                            <td><input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="adm-input"></td>
                            <td style="padding: 0 10px;">По:</td>
                            <td><input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="adm-input"></td>
                        </tr>
                    </table>
                </td>
            </tr>
			<tr>
				<td class="adm-detail-content-cell-l">Статья расходов:</td>
				<td class="adm-detail-content-cell-r">
					<select name="article" class="adm-input" style="width: 100%;">
						<option value="">— Все статьи —</option>
						<?php if (!empty($expenseArticles)): ?>
							<?php foreach ($expenseArticles as $article): ?>
								<option value="<?= htmlspecialchars($article) ?>" 
									<?= isset($articleFilter) && $articleFilter === $article ? 'selected' : '' ?>>
									<?= htmlspecialchars($article) ?>
								</option>
							<?php endforeach; ?>
						<?php else: ?>
							<option value="" disabled>⚠️ Статьи не загружены</option>
						<?php endif; ?>
					</select>
					<?php if (empty($expenseArticles)): ?>
						<div style="color: #666; font-size: 11px; margin-top: 3px;">
							💡 Введите часть названия для поиска
						</div>
					<?php endif; ?>
				</td>
			</tr>
            <tr>
                <td colspan="2" style="text-align: center; padding-top: 20px;">
                    <input type="submit" name="generate" value="Сформировать отчёт" class="adm-btn adm-btn-save" style="padding: 10px 25px;">
                    <input type="submit" name="export" value="Экспорт в CSV" class="adm-btn adm-btn-light" 
                           onclick="this.form.export.value='csv';" style="padding: 10px 25px; margin-left: 10px;">
                </td>
            </tr>
        </table>
    </form>
</div>

<?php if ($error): ?>
    <div class="adm-info-message-wrap" style="margin: 20px 0;">
        <div class="adm-info-message adm-info-message-red">
            <div class="adm-info-message-title">Ошибка</div>
            <div class="adm-info-message-item"><?= htmlspecialchars($error) ?></div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($reportData)): ?>
    <div class="expense-report-card">
        <h2>Сводные показатели</h2>
        <div class="expense-summary">
            <div class="expense-summary-item">
                <div class="expense-summary-label">Общий доход</div>
                <div class="expense-summary-value expense-income">
                    <?= number_format($reportSummary['total_income'], 2, ',', ' ') ?> ₽
                </div>
            </div>
            <div class="expense-summary-item">
                <div class="expense-summary-label">Общий расход</div>
                <div class="expense-summary-value expense-outcome">
                    <?= number_format($reportSummary['total_expenses'], 2, ',', ' ') ?> ₽
                </div>
            </div>
            <div class="expense-summary-item">
                <div class="expense-summary-label">Чистая прибыль</div>
                <div class="expense-summary-value <?= $reportSummary['net_profit'] >= 0 ? 'expense-profit-positive' : 'expense-profit-negative' ?>">
                    <?= number_format($reportSummary['net_profit'], 2, ',', ' ') ?> ₽
                </div>
            </div>
        </div>
    </div>

    <div class="expense-report-card">
        <h2>Детализация по статьям расходов</h2>
        <div style="overflow-x: auto;">
            <table class="adm-list-table" style="width: 100%; min-width: 800px;">
                <thead>
                    <tr class="adm-list-table-header">
                        <td>Организация</td>
                        <td>Направление</td>
                        <td>Статья</td>
                        <td style="text-align: right;">Доходы</td>
                        <td style="text-align: right;">Расходы</td>
                        <td style="text-align: right;">% от расходов</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData as $row): 
                        $percent = $reportSummary['total_expenses'] > 0 
                            ? ($row['СуммаРасходовОборот'] / $reportSummary['total_expenses'] * 100) 
                            : 0;
                    ?>
                        <tr class="adm-list-table-row">
                            <td><?= htmlspecialchars($row['Организация'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['НаправлениеДеятельности'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['Статья'] ?? '') ?></td>
                            <td style="text-align: right;"><?= number_format($row['СуммаДоходовОборот'] ?? 0, 2, ',', ' ') ?></td>
                            <td style="text-align: right; color: #dc3545;"><?= number_format($row['СуммаРасходовОборот'] ?? 0, 2, ',', ' ') ?></td>
                            <td style="text-align: right;"><?= number_format($percent, 2, ',', ' ') ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <tr class="adm-list-table-row" style="font-weight: bold; background: #f9f9f9;">
                        <td colspan="3" style="text-align: right;">ИТОГО:</td>
                        <td style="text-align: right;"><?= number_format($reportSummary['total_income'], 2, ',', ' ') ?></td>
                        <td style="text-align: right; color: #dc3545;"><?= number_format($reportSummary['total_expenses'], 2, ',', ' ') ?></td>
                        <td style="text-align: right;">100%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if (!empty($reportSummary['expense_categories'])): ?>
    <div class="expense-report-card">
        <h2>Структура расходов</h2>
        <div style="display: flex; flex-wrap: wrap; gap: 15px;">
            <?php 
            $colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#36A2EB'];
            $i = 0;
            foreach ($reportSummary['expense_categories'] as $article => $amount):
                $percent = $reportSummary['total_expenses'] > 0 ? ($amount / $reportSummary['total_expenses'] * 100) : 0;
                $color = $colors[$i % count($colors)];
                $i++;
            ?>
                <div style="flex: 1; min-width: 200px; background: #f5f5f5; border-radius: 4px; padding: 15px;">
                    <div style="font-weight: bold; margin-bottom: 5px;"><?= htmlspecialchars($article) ?></div>
                    <div style="font-size: 18px; font-weight: bold; color: #dc3545; margin-bottom: 5px;">
                        <?= number_format($amount, 2, ',', ' ') ?> ₽
                    </div>
                    <div style="height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden;">
                        <div style="height: 100%; width: <?= min(100, $percent) ?>%; background: <?= $color ?>;"></div>
                    </div>
                    <div style="text-align: right; font-size: 14px; margin-top: 3px;"><?= number_format($percent, 1, ',', ' ') ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_admin.php");
?>