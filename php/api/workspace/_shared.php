<?php
/**
 * _shared.php — общие функции для всех workspace-эндпоинтов.
 * Подключается через require_once __DIR__ . '/_shared.php';
 */

// ── Единая диагностика (SSOT) ──────────────────────────────────────────────
final class WsDiag {
    private static array $events = [];

    public static function enabled(): bool {
        // Можно включить явно: ?ws_diag=Y
        // Или использовать админский debug: ?debug=Y (если WS_DEBUG=true в endpoint).
        return (($_GET['ws_diag'] ?? 'N') === 'Y') || (defined('WS_DEBUG') && WS_DEBUG);
    }

    public static function level(): int {
        $level = (int)($_GET['ws_diag_level'] ?? 1);
        // 1 — минимум, 2 — расширенный, 3 — очень подробный.
        return max(1, min(3, $level));
    }

    public static function add(string $code, array $data = [], int $minLevel = 1): void {
        if (!self::enabled() || self::level() < $minLevel) return;
        self::$events[] = [
            'ts'   => date('c'),
            'code' => $code,
            'data' => $data,
        ];
    }

    public static function dump(): array {
        if (!self::enabled()) return [];
        return [
            'diag_enabled' => true,
            'diag_level'   => self::level(),
            'request_uri'  => $_SERVER['REQUEST_URI'] ?? null,
            'events'       => self::$events,
        ];
    }
}

// ── HTTP хелперы ──────────────────────────────────────────────────────────

function wsJsonOk(array $data): void {
    $r = ['status' => 'ok', 'data' => $data];
    if (defined('WS_DEBUG') && WS_DEBUG) {
        $r['debug'] = [
            'execution_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000),
            'memory_mb'    => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function wsJsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    $r = ['status' => 'error', 'message' => $msg];
    $diag = WsDiag::dump();
    if (!empty($diag)) $r['debug'] = $diag;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Единая обработка исключений для endpoint'ов.
 * Пишет в лог и добавляет подробности в WsDiag (если включен).
 */
function wsHandleThrowable(string $channel, \Throwable $e): void {
    // Лог в системный лог bproc.
    BpLog::error($channel, 'Fatal: ' . $e->getMessage(), ['line' => $e->getLine(), 'file' => $e->getFile()]);

    // В ответе debug показываем подробности только в режиме диагностики.
    WsDiag::add('exception', [
        'channel' => $channel,
        'type'    => get_class($e),
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], 1);
    WsDiag::add('exception_trace', [
        'trace' => explode("\n", $e->getTraceAsString()),
    ], 3);

    wsJsonErr('internal_error', 500);
}

// ── Загрузка конфигов ─────────────────────────────────────────────────────

function wsLoadWorkspaceConfig(string $processKey): ?array {
    $safe = preg_replace('/[^a-z0-9_]/', '', $processKey);
    // Путь к workspace-конфигу процесса берём из единого реестра путей.
    $path = wsDocPath(wsBprocProcessesDir() . '/' . $safe . '_workspace.php');
    WsDiag::add('workspace_config_check', ['process_key' => $safe, 'path' => $path, 'exists' => file_exists($path)]);
    if (!file_exists($path)) return null;
    $cfg = require $path;
    return is_array($cfg) ? $cfg : null;
}

function wsLoadProcessConfig(string $processKey): ?array {
    $safe = preg_replace('/[^a-z0-9_]/', '', $processKey);
    WsDiag::add('process_config_start', ['process_key' => $safe], 2);

    // Сначала пробуем стандартный проектный хелпер, если он подключен в окружении.
    if (function_exists('findProcessConfig')) {
        WsDiag::add('process_config_findProcessConfig_call', ['process_key' => $safe], 2);
        $cfg = findProcessConfig($safe);
        if (is_array($cfg) && isset($cfg['config']) && is_array($cfg['config'])) {
            WsDiag::add('process_config_findProcessConfig_ok', ['process_key' => $safe], 2);
            return $cfg['config'];
        }
        WsDiag::add('process_config_findProcessConfig_miss', ['process_key' => $safe], 2);
    } else {
        WsDiag::add('process_config_findProcessConfig_absent', [], 2);
    }

    // Fallback: читаем основной конфиг напрямую из /local/bproc/processes/<key>.php
    $path = wsDocPath(wsBprocProcessesDir() . '/' . $safe . '.php');
    WsDiag::add('process_config_file_check', ['process_key' => $safe, 'path' => $path, 'exists' => file_exists($path)]);
    if (!file_exists($path)) return null;

    $cfg = require $path;
    if (!is_array($cfg)) return null;

    // Нормализуем формат: где-то файл возвращает ['config' => [...]], где-то сразу [...]
    return isset($cfg['config']) && is_array($cfg['config']) ? $cfg['config'] : $cfg;
}

function wsLoadRoleProfile(string $roleKey): array {
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($roleKey));
    $path = wsDocPath(wsBprocRolesDir() . '/' . $safe . '.php');
    if (!file_exists($path)) $path = wsDocPath(wsBprocRolesDir() . '/_default.php');
    WsDiag::add('role_profile_check', ['role_key' => $safe, 'path' => $path, 'exists' => file_exists($path)], 2);
    if (!file_exists($path)) return ['role_key'=>'_default','label'=>'Сотрудник','processes'=>[],'analytics_processes'=>[]];
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

/**
 * Собирает список UF-полей для SELECT из конфига процесса.
 * Берёт все поля типа "field" из секции roles + amount_field из ws-конфига.
 */
function collectFieldsFromConfig(array $config, array $wsCfg): array {
    $base = ['ID', 'TITLE', 'ASSIGNED_BY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'DATE_CREATE'];

    foreach ($config['roles'] ?? [] as $roleDef) {
        if (($roleDef['type'] ?? '') !== 'field') continue;
        $field = (string)($roleDef['field'] ?? '');
        if ($field !== '' && !in_array($field, $base, true)) {
            $base[] = $field;
        }
    }

    $amountField = (string)($wsCfg['amount_field'] ?? '');
    if ($amountField !== '' && !in_array($amountField, $base, true)) {
        $base[] = $amountField;
    }

    WsDiag::add('collect_fields_from_config', ['fields' => $base], 2);
    return $base;
}

/**
 * Единая точка проверки process-конфигов для endpoint'ов.
 * При ошибке сам вернет wsJsonErr с детальной диагностикой (если включена).
 */
function wsRequireProcessConfigs(string $processKey): array {
    $primaryProcessesDir = wsDocPath(WS_BPROC_CONFIG_ROOT_PRIMARY . '/processes');
    $fallbackProcessesDir = wsDocPath(WS_BPROC_CONFIG_ROOT_FALLBACK . '/processes');
    WsDiag::add('config_root_resolution', [
        'forced_ws_cfg_root' => $_GET['ws_cfg_root'] ?? null,
        'primary_root' => WS_BPROC_CONFIG_ROOT_PRIMARY,
        'primary_processes_dir' => $primaryProcessesDir,
        'primary_exists' => is_dir($primaryProcessesDir),
        'fallback_root' => WS_BPROC_CONFIG_ROOT_FALLBACK,
        'fallback_processes_dir' => $fallbackProcessesDir,
        'fallback_exists' => is_dir($fallbackProcessesDir),
        'selected_config_root' => wsBprocConfigRoot(),
    ]);

    $wsCfg = wsLoadWorkspaceConfig($processKey);
    $cfg   = wsLoadProcessConfig($processKey);

    WsDiag::add('process_config_result', [
        'process_key' => $processKey,
        'workspace_config_found' => $wsCfg !== null,
        'process_config_found'   => $cfg !== null,
        'bproc_runtime_root'     => wsBprocRoot(),
        'bproc_config_root'      => wsBprocConfigRoot(),
    ]);

    if (!$wsCfg || !$cfg) {
        wsJsonErr('process_not_found', 404);
    }

    return [$wsCfg, $cfg];
}

// ── Вычисления ────────────────────────────────────────────────────────────

function computeOverdue(array $state, array $stepsConfig, string $currentStep): array {
    $stepCfg       = $stepsConfig[$currentStep] ?? [];
    $deadlineHours = $stepCfg['deadline_hours'] ?? null;
    if ($deadlineHours === null) return ['is_overdue'=>false,'overdue_hours'=>0,'elapsed_hours'=>0];

    $startTs = null;
    foreach (array_reverse($state['stages'][$currentStep]['history'] ?? []) as $ev) {
        if (($ev['status'] ?? '') === 'work') { $startTs = strtotime($ev['date'] ?? ''); break; }
    }
    if (!$startTs) return ['is_overdue'=>false,'overdue_hours'=>0,'elapsed_hours'=>0];

    $elapsed  = time() - $startTs;
    $elapsedH = round($elapsed / 3600, 1);
    $isOver   = $elapsed > $deadlineHours * 3600;
    $overH    = $isOver ? (int)round(($elapsed - $deadlineHours * 3600) / 3600) : 0;
    return ['is_overdue' => $isOver, 'overdue_hours' => $overH, 'elapsed_hours' => $elapsedH];
}

function computeProgress(array $state, array $stepsConfig): int {
    $total = 0; $done = 0;
    foreach ($stepsConfig as $stepKey => $stepCfg) {
        if (in_array($stepCfg['type'] ?? '', ['auto','final'], true)) continue;
        $total++;
        if (($state['stages'][$stepKey]['status'] ?? '') === 'done') $done++;
    }
    return $total > 0 ? (int)round($done / $total * 100) : 0;
}

function computeNeedsAction(string $currentStep, array $stepsConfig, array $circleConfig, array $rolesConfig, array $docFields, array $state, RoleResolver $resolver): bool {
    // Служебные значения завершённого процесса: действий не требуется.
    if (!$currentStep || in_array($currentStep, ['_done', '_complete'], true)) return false;

    $stepType = $stepsConfig[$currentStep]['type'] ?? 'human';
    if (in_array($stepType, ['auto','final','wait'], true)) return false;

    if (in_array($stepType, ['human','subprocess','approval'], true)) {
        return (int)($docFields['ASSIGNED_BY_ID'] ?? 0) === $resolver->userId
            || $resolver->resolveRole($rolesConfig, $docFields) !== null;
    }
    if ($stepType === 'circle') {
        $circleCfg = $circleConfig[$currentStep] ?? [];
        if (!$resolver->isApprover($circleCfg, $rolesConfig, $docFields)) return false;
        // Уже проголосовал?
        $version = $state['approvals'][$currentStep]['viewVersion'] ?? 1;
        foreach ($state['approvals'][$currentStep]['history'] ?? [] as $ev) {
            if ((int)($ev['version']??0) === (int)$version && (int)($ev['userId']??0) === $resolver->userId) return false;
        }
        return true;
    }
    return false;
}

// ── RoleResolver ──────────────────────────────────────────────────────────

class RoleResolver {
    public int $userId;
    private array $userDepts;
    private array $groupCache = [];

    public function __construct(int $userId) {
        $this->userId    = $userId;
        $this->userDepts = $this->loadDepts();
    }

    public function resolveRole(array $rolesConfig, array $docFields): ?string {
        foreach ($rolesConfig as $key => $def) {
            if ($this->matches($def, $docFields)) return $key;
        }
        return null;
    }

    public function isApprover(array $circleConfig, array $rolesConfig, array $docFields): bool {
        foreach ($circleConfig['approvers'] ?? [] as $roleKey) {
            $def = $rolesConfig[$roleKey] ?? null;
            if ($def && $this->matches($def, $docFields)) return true;
        }
        return false;
    }

    public function resolveToUserIds(array $roleDef, array $docFields): array {
        switch ($roleDef['type'] ?? '') {
            case 'user':  return [(int)$roleDef['id']];
            case 'field': $v = (int)($docFields[$roleDef['field']??'']??0); return $v>0?[$v]:[];
            case 'dept':  return $this->deptUsers((int)$roleDef['id']);
            case 'group': return $this->groupUsers((int)$roleDef['id']);
            default:      return [];
        }
    }

    private function matches(array $def, array $fields): bool {
        switch ($def['type'] ?? '') {
            case 'user':  return (int)($def['id']??0) === $this->userId;
            case 'field': return (int)($fields[$def['field']??'']??0) === $this->userId;
            case 'dept':  return in_array((int)($def['id']??0), $this->userDepts, true);
            case 'group': return $this->inGroup((int)($def['id']??0));
            default:      return false;
        }
    }

    private function loadDepts(): array {
        if (!\Bitrix\Main\Loader::includeModule('intranet')) return [];
        return array_map('intval', (array)\CIntranetUtils::GetUserDepartments($this->userId));
    }

    private function inGroup(int $id): bool {
        if (!$id) return false;
        if (!isset($this->groupCache[$id])) {
            $r = \CSocNetUserToGroup::GetList([],['USER_ID'=>$this->userId,'GROUP_ID'=>$id,'ROLE'=>SONET_ROLES_USER],false,false,['ID']);
            $this->groupCache[$id] = (bool)$r->Fetch();
        }
        return $this->groupCache[$id];
    }

    private function deptUsers(int $id): array {
        if (!$id) return [];
        $res = \CUser::GetList('ID','ASC',['UF_DEPARTMENT'=>$id,'ACTIVE'=>'Y'],['FIELDS'=>['ID']]);
        $ids = [];
        while ($r = $res->Fetch()) $ids[] = (int)$r['ID'];
        return $ids;
    }

    private function groupUsers(int $id): array {
        if (!$id) return [];
        $res = \CSocNetUserToGroup::GetList([],['GROUP_ID'=>$id],false,false,['USER_ID']);
        $ids = [];
        while ($r = $res->Fetch()) $ids[] = (int)$r['USER_ID'];
        return $ids;
    }
}
