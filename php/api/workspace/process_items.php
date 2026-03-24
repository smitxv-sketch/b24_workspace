<?php
/**
 * GET /local/api/workspace/process_items.php
 * Список заявок (ActionItems) для конкретного процесса.
 *
 * Параметры:
 *   process_key (required) — например 'deal_tkp'
 *   filter      (optional) — 'all' | 'overdue' | 'action' | 'wait'
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once __DIR__ . '/_paths.php';
require_once wsDocPath('/bitrix/modules/main/include/prolog_before.php');
require_once wsDocPath(wsBprocLibRoot() . '/BpLog.php');
require_once wsDocPath(wsBprocLibRoot() . '/BpStorage.php');
require_once wsDocPath(wsBprocRoot() . '/config_bp_constants.php');
require_once wsDocPath(wsBprocRoot() . '/config_process_steps.php');
require_once __DIR__ . '/_shared.php';

BpLog::registerFatalHandler('ws_process_items');
BpLog::init(fn(string $m) => null, BpLog::LEVEL_OFF, BpLog::LEVEL_DEBUG);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

global $USER;
if (!$USER->IsAuthorized()) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'unauthorized']); exit; }

$currentUserId = (int)$USER->GetID();
define('WS_DEBUG', !empty($_GET['debug']) && $_GET['debug'] === 'Y' && $USER->IsAdmin());

try {
    BpLog::info('ws_process_items', 'START', ['userId' => $currentUserId]);

    $processKey = preg_replace('/[^a-z0-9_]/', '', $_GET['process_key'] ?? '');
    $filter     = $_GET['filter'] ?? 'all';

    if (!$processKey) wsJsonErr('process_key_required');

    // 1. Загружаем конфиги
    $wsCfg      = wsLoadWorkspaceConfig($processKey);
    $config     = wsLoadProcessConfig($processKey);
    if (!$wsCfg || !$config) wsJsonErr('process_not_found', 404);

    $categoryId   = $config['match']['categoryId'] ?? 1;
    $entityTypeId = $config['match']['entityTypeId'] ?? 2;

    // 2. Роль пользователя в воркспейсе этого процесса
    $resolver     = new RoleResolver($currentUserId);
    $wsRoleKey    = '_default';
    // Определяем роль через первую подходящую роль из конфига процесса
    // (реальная логика — через RoleResolver по всем документам)

    $wsRoleCfg    = $wsCfg['workspace_roles'][$wsRoleKey]
                 ?? $wsCfg['workspace_roles']['_default']
                 ?? ['sections' => ['alert','timeline'], 'folders' => [], 'filters' => []];

    // 3. Получаем документы
    $deals = fetchActiveDeals($entityTypeId, $categoryId);

    // 4. Строим ActionItems
    $items    = [];
    $sections = ['attention' => 0, 'active' => 0, 'closed' => 0];

    foreach ($deals as $deal) {
        $docId    = (int)$deal['ID'];
        $docFields = $deal;

        // Читаем state
        $fieldCode = getFieldCode($entityTypeId, 'json');
        $state     = BpStorage::readJson($entityTypeId, $docId, $fieldCode);

        $currentStep  = $state['nav']['step'] ?? null;
        $stepsConfig  = $config['steps'] ?? [];
        $circleConfig = $config['circles'] ?? [];
        $rolesConfig  = $config['roles'] ?? [];

        // Вычисляем
        $overdue     = $currentStep ? computeOverdue($state, $stepsConfig, $currentStep) : ['is_overdue'=>false,'overdue_hours'=>0,'elapsed_hours'=>0];
        $needsAction = $currentStep ? computeNeedsAction($currentStep, $stepsConfig, $circleConfig, $rolesConfig, $docFields, $state, $resolver) : false;
        $isWaiting   = $currentStep && (($stepsConfig[$currentStep]['type'] ?? '') === 'wait');
        $progress    = computeProgress($state, $stepsConfig);
        $version     = (int)($state['version'] ?? $state['nav']['version'] ?? 1);
        $isRework    = (bool)($state['isRework'] ?? false);

        // Секция
        $stageSemanticId = $deal['STAGE_SEMANTIC_ID'] ?? '';
        if (in_array($stageSemanticId, ['S', 'F'], true)) {
            $section = 'closed';
        } elseif ($overdue['is_overdue'] || $needsAction) {
            $section = 'attention';
        } else {
            $section = 'active';
        }
        $sections[$section]++;

        // Цвет акцента
        if ($overdue['is_overdue']) { $accentColor = 'red'; $statusColor = 'red'; $statusLabel = 'Просрочено'; }
        elseif ($needsAction)       { $accentColor = 'blue'; $statusColor = 'blue'; $statusLabel = 'Нужно решение'; }
        elseif ($isWaiting)         { $accentColor = 'amber'; $statusColor = 'amber'; $statusLabel = 'Ожидание'; }
        elseif ($section === 'closed' && $stageSemanticId === 'S') { $accentColor = 'green'; $statusColor = 'green'; $statusLabel = 'WON'; }
        elseif ($section === 'closed') { $accentColor = 'red'; $statusColor = 'red'; $statusLabel = 'Отклонено'; }
        else   { $accentColor = 'blue'; $statusColor = 'blue'; $statusLabel = 'В работе'; }

        $stepLabel = $currentStep ? ($stepsConfig[$currentStep]['label'] ?? $currentStep) : '';
        $deadlineH = $currentStep ? ($stepsConfig[$currentStep]['deadline_hours'] ?? null) : null;
        $hint = $overdue['is_overdue']
            ? $overdue['elapsed_hours'] . ' ч из ' . $deadlineH . ' · просрочено'
            : ($deadlineH ? $overdue['elapsed_hours'] . ' ч из ' . $deadlineH : '');

        // Участники (превью аватаров)
        $participantsPreview = buildParticipantsPreview($config, $docFields, $state, $currentStep);

        $item = [
            'id'                  => 'DEAL_' . $docId,
            'entity_type'         => 'deal',
            'entity_id'           => $docId,
            'title'               => $deal['TITLE'] ?? '#' . $docId,
            'entity_url'          => '/crm/deal/details/' . $docId . '/',
            'process_key'         => $processKey,
            'current_step'        => $currentStep,
            'current_step_label'  => $stepLabel,
            'version'             => $version,
            'is_rework'           => $isRework,
            'progress_percent'    => $progress,
            'is_overdue'          => $overdue['is_overdue'],
            'overdue_hours'       => $overdue['overdue_hours'],
            'elapsed_hours'       => $overdue['elapsed_hours'],
            'needs_action'        => $needsAction,
            'is_waiting'          => $isWaiting,
            'status_label'        => $statusLabel,
            'status_color'        => $statusColor,
            'accent_color'        => $accentColor,
            'deadline_hours'      => $deadlineH,
            'hint'                => $hint,
            'participants_preview'=> $participantsPreview,
            'section'             => $section,
        ];

        // Фильтрация
        if ($filter === 'overdue' && !$overdue['is_overdue']) continue;
        if ($filter === 'action'  && !$needsAction)           continue;
        if ($filter === 'wait'    && !$isWaiting)             continue;

        $items[] = $item;
    }

    // Сортировка: attention первые, внутри — overdue > needsAction > остальные
    usort($items, function($a, $b) {
        $order = ['attention' => 0, 'active' => 1, 'closed' => 2];
        $so = ($order[$a['section']] ?? 9) <=> ($order[$b['section']] ?? 9);
        if ($so !== 0) return $so;
        return ((int)$b['is_overdue'] <=> (int)$a['is_overdue'])
            ?: ((int)$b['needs_action'] <=> (int)$a['needs_action']);
    });

    wsJsonOk([
        'process_key'    => $processKey,
        'workspace_config'=> [
            'sections' => $wsRoleCfg['sections'] ?? [],
            'folders'  => $wsRoleCfg['folders'] ?? [],
            'filters'  => $wsRoleCfg['filters'] ?? [],
        ],
        'items'          => $items,
        'sections'       => [
            'attention' => ['label' => 'Требуют внимания', 'count' => $sections['attention']],
            'active'    => ['label' => 'В работе',         'count' => $sections['active']],
            'closed'    => ['label' => 'Закрыты',          'count' => $sections['closed']],
        ],
    ]);

} catch (\Throwable $e) {
    BpLog::error('ws_process_items', 'Fatal: ' . $e->getMessage(), ['line' => $e->getLine()]);
    wsJsonErr('internal_error', 500);
}

// ── Вспомогательные функции ───────────────────────────────────────────────

function fetchActiveDeals(int $entityTypeId, int $categoryId): array {
    \Bitrix\Main\Loader::includeModule('crm');
    if ($entityTypeId === 2) {
        $res = \CCrmDeal::GetListEx(
            ['DATE_CREATE' => 'DESC'],
            ['CATEGORY_ID' => $categoryId, 'CHECK_PERMISSIONS' => 'N'],
            false, ['nPageSize' => 200, 'iNumPage' => 1],
            ['ID','TITLE','ASSIGNED_BY_ID','STAGE_ID','STAGE_SEMANTIC_ID','DATE_CREATE','UF_GIP','UF_CONTRACT_AMOUNT']
        );
        $rows = [];
        while ($r = $res->Fetch()) $rows[] = $r;
        return $rows;
    }
    // Для смарт-процессов — через factory
    $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
    if (!$factory) return [];
    $collection = $factory->getItemCollection(['filter'=>['CATEGORY_ID'=>$categoryId,'!STAGE_SEMANTIC_ID'=>'F'],'limit'=>200]);
    $rows = [];
    foreach ($collection as $item) {
        $rows[] = ['ID'=>$item->getId(),'TITLE'=>$item->getTitle(),'ASSIGNED_BY_ID'=>$item->getAssignedById(),'STAGE_SEMANTIC_ID'=>$item->getStageSemanticId()];
    }
    return $rows;
}

function buildParticipantsPreview(array $config, array $docFields, array $state, ?string $currentStep): array {
    $preview = [];
    // Для круга согласования — добавляем голосующих
    if ($currentStep && isset($config['circles'][$currentStep])) {
        $history = $state['approvals'][$currentStep]['history'] ?? [];
        $voted   = [];
        foreach ($history as $ev) { $voted[(int)$ev['userId']] = $ev['verdict'] ?? 'wait'; }

        foreach ($config['circles'][$currentStep]['approvers'] ?? [] as $roleKey) {
            $roleDef = $config['roles'][$roleKey] ?? null;
            if (!$roleDef) continue;
            if ($roleDef['type'] === 'user') {
                $uid    = (int)$roleDef['id'];
                $verdict= $voted[$uid] ?? 'wait';
                $preview[] = ['initials' => getInitials($roleDef['label'] ?? ''), 'color' => $verdict === 'approve' ? 'green' : ($verdict === 'reject' ? 'red' : 'amber'), 'status' => $verdict];
            }
        }
    }
    return array_slice($preview, 0, 5);
}

function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0],0,1).mb_substr($parts[1],0,1));
    return mb_strtoupper(mb_substr($name,0,2));
}
