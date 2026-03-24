<?php
/**
 * GET /local/api/workspace/bootstrap.php
 * Первый запрос при загрузке воркспейса.
 * Возвращает: профиль пользователя, навигацию, флаги.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpLog.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpStorage.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/config_bp_constants.php';

// ── Инициализация логирования ─────────────────────────────────────────────
BpLog::registerFatalHandler('ws_bootstrap');
BpLog::init(fn(string $m) => null, BpLog::LEVEL_OFF, BpLog::LEVEL_DEBUG);

// ── HTTP заголовки ────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Авторизация ───────────────────────────────────────────────────────────
global $USER;
if (!$USER->IsAuthorized()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$currentUserId = (int)$USER->GetID();
define('WS_DEBUG', !empty($_GET['debug']) && $_GET['debug'] === 'Y' && $USER->IsAdmin());

// ── Хелперы ───────────────────────────────────────────────────────────────
function jsonOk(array $data): void {
    $r = ['status' => 'ok', 'data' => $data];
    if (WS_DEBUG) $r['debug'] = ['user_id' => $GLOBALS['currentUserId'], 'ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000)];
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Основная логика ───────────────────────────────────────────────────────
try {
    BpLog::info('ws_bootstrap', 'START', ['userId' => $currentUserId]);

    // 1. Данные пользователя
    $userRow = \CUser::GetByID($currentUserId)->Fetch();
    if (!$userRow) jsonErr('user_not_found', 404);

    $userName      = trim($userRow['NAME'] . ' ' . $userRow['LAST_NAME']);
    $workspaceRole = trim($userRow['UF_USR_1774331641609'] ?? '') ?: '_default';

    // 2. Профиль роли воркспейса
    $roleProfile = loadWorkspaceRoleProfile($workspaceRole);

    // 3. Формируем навигацию
    $nav = [];
    foreach ($roleProfile['processes'] ?? [] as $processKey => $processDef) {
        $wsCfg = loadWorkspaceConfig($processKey);
        $badge = $wsCfg ? computeBadge($processKey, $wsCfg, $currentUserId) : 0;

        $nav[] = [
            'key'      => $processKey,
            'label'    => $processDef['label'] ?? $processKey,
            'icon'     => $processDef['icon'] ?? 'grid',
            'badge'    => $badge,
            'disabled' => ($wsCfg === null), // нет workspace-конфига — отключён
            'disabled_reason' => $wsCfg === null ? 'coming_soon' : null,
        ];
    }

    // Таб аналитики — если есть хоть один процесс с аналитикой
    $canAnalytics = !empty($roleProfile['analytics_processes']);

    jsonOk([
        'user' => [
            'id'                   => $currentUserId,
            'name'                 => $userName,
            'workspace_role'       => $workspaceRole,
            'workspace_role_label' => $roleProfile['label'] ?? $workspaceRole,
        ],
        'nav'          => $nav,
        'can_analytics'=> $canAnalytics,
    ]);

} catch (\Throwable $e) {
    BpLog::error('ws_bootstrap', 'Fatal: ' . $e->getMessage(), ['line' => $e->getLine()]);
    jsonErr('internal_error', 500);
}

// ── Вспомогательные функции ───────────────────────────────────────────────

function loadWorkspaceRoleProfile(string $roleKey): array {
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($roleKey));
    $path = $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/workspace_roles/' . $safe . '.php';
    if (!file_exists($path)) {
        $path = $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/workspace_roles/_default.php';
    }
    if (!file_exists($path)) {
        return ['role_key' => '_default', 'label' => 'Сотрудник', 'processes' => [], 'analytics_processes' => []];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

function loadWorkspaceConfig(string $processKey): ?array {
    $safe = preg_replace('/[^a-z0-9_]/', '', $processKey);
    $path = $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/processes/' . $safe . '_workspace.php';
    if (!file_exists($path)) return null;
    $cfg = require $path;
    return is_array($cfg) ? $cfg : null;
}

function computeBadge(string $processKey, array $wsCfg, int $userId): int {
    // TODO: реальный подсчёт заявок требующих внимания
    // Сейчас возвращаем 0 — реализовать через process_items логику
    return 0;
}
