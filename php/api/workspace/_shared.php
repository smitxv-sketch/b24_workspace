<?php
/**
 * _shared.php — общие функции для всех workspace-эндпоинтов.
 * Подключается через require_once __DIR__ . '/_shared.php';
 */

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
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Загрузка конфигов ─────────────────────────────────────────────────────

function wsLoadWorkspaceConfig(string $processKey): ?array {
    $safe = preg_replace('/[^a-z0-9_]/', '', $processKey);
    // Путь к workspace-конфигу процесса берём из единого реестра путей.
    $path = wsDocPath(WS_BPROC_PROCESSES_DIR . '/' . $safe . '_workspace.php');
    if (!file_exists($path)) return null;
    $cfg = require $path;
    return is_array($cfg) ? $cfg : null;
}

function wsLoadProcessConfig(string $processKey): ?array {
    $safe = preg_replace('/[^a-z0-9_]/', '', $processKey);

    // Сначала пробуем стандартный проектный хелпер, если он подключен в окружении.
    if (function_exists('findProcessConfig')) {
        $cfg = findProcessConfig($safe);
        if (is_array($cfg) && isset($cfg['config']) && is_array($cfg['config'])) {
            return $cfg['config'];
        }
    }

    // Fallback: читаем основной конфиг напрямую из /local/bproc/processes/<key>.php
    $path = wsDocPath(WS_BPROC_PROCESSES_DIR . '/' . $safe . '.php');
    if (!file_exists($path)) return null;

    $cfg = require $path;
    if (!is_array($cfg)) return null;

    // Нормализуем формат: где-то файл возвращает ['config' => [...]], где-то сразу [...]
    return isset($cfg['config']) && is_array($cfg['config']) ? $cfg['config'] : $cfg;
}

function wsLoadRoleProfile(string $roleKey): array {
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($roleKey));
    $path = wsDocPath(WS_BPROC_ROLES_DIR . '/' . $safe . '.php');
    if (!file_exists($path)) $path = wsDocPath(WS_BPROC_ROLES_DIR . '/_default.php');
    if (!file_exists($path)) return ['role_key'=>'_default','label'=>'Сотрудник','processes'=>[],'analytics_processes'=>[]];
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
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
