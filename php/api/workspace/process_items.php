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
    [$wsCfg, $config] = wsRequireProcessConfigs($processKey);

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
    $fields = collectFieldsFromConfig($config, $wsCfg);
    $deals  = fetchActiveDeals($entityTypeId, $categoryId, $fields);
    $stageColorMap = loadStageColorMap($entityTypeId, $categoryId);
    $rightsFieldCode = getFieldCode($entityTypeId, 'rights');

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

        // Секция + статус.
        $stageSemanticId = $deal['STAGE_SEMANTIC_ID'] ?? '';
        $stageId = (string)($deal['STAGE_ID'] ?? '');

        // Завершённые сделки: всегда closed, без действия/просрочки.
        if (!$currentStep || in_array($currentStep, ['_done', '_complete'], true)) {
            $section = 'closed';
            $needsAction = false;
            $overdue = ['is_overdue' => false, 'overdue_hours' => 0, 'elapsed_hours' => 0];
            $statusLabel = 'Завершён';
            $statusColor = 'green';
            $accentColor = 'green';
        } else {
            if (in_array($stageSemanticId, ['S', 'F'], true)) {
                $section = 'closed';
            } elseif ($overdue['is_overdue'] || $needsAction) {
                $section = 'attention';
            } else {
                $section = 'active';
            }

            // Цвет акцента для незавершённых.
            if ($overdue['is_overdue']) { $accentColor = 'red'; $statusColor = 'red'; $statusLabel = 'Просрочено'; }
            elseif ($needsAction)       { $accentColor = 'blue'; $statusColor = 'blue'; $statusLabel = 'Нужно решение'; }
            elseif ($isWaiting)         { $accentColor = 'amber'; $statusColor = 'amber'; $statusLabel = 'Ожидание'; }
            elseif ($section === 'closed' && $stageSemanticId === 'S') { $accentColor = 'green'; $statusColor = 'green'; $statusLabel = 'WON'; }
            elseif ($section === 'closed') { $accentColor = 'red'; $statusColor = 'red'; $statusLabel = 'Отклонено'; }
            else   { $accentColor = 'blue'; $statusColor = 'blue'; $statusLabel = 'В работе'; }
        }
        $sections[$section]++;

        // Цвет стадии CRM (hex) для визуального акцента карточки.
        $stageColor = resolveStageColorHex($stageColorMap, $stageId);

        $stepLabel = $currentStep ? ($stepsConfig[$currentStep]['label'] ?? $currentStep) : '';
        $deadlineH = $currentStep ? ($stepsConfig[$currentStep]['deadline_hours'] ?? null) : null;
        $hint = $overdue['is_overdue']
            ? $overdue['elapsed_hours'] . ' ч из ' . $deadlineH . ' · просрочено'
            : ($deadlineH ? $overdue['elapsed_hours'] . ' ч из ' . $deadlineH : '');

        $createdAt = (string)($deal['DATE_CREATE'] ?? '');
        $createdAtLabel = '';
        $daysInWork = null;
        if ($createdAt !== '') {
            $createdTs = strtotime($createdAt);
            if ($createdTs) {
                $createdAtLabel = date('d.m.Y', $createdTs);
                $daysInWork = max(0, (int)floor((time() - $createdTs) / 86400));
            }
        }

        // Ссылка на основную папку сделки (на уровень выше рабочих подпапок).
        $folderUrl = null;
        $rightsJson = BpStorage::readJson($entityTypeId, $docId, $rightsFieldCode);
        $rootFolderId = resolveDealRootFolderIdFromRights($rightsJson, $wsRoleCfg);
        if ($rootFolderId > 0) {
            $folderUrl = getFolderUrl($rootFolderId);
        }

        // Участники (превью аватаров)
        $participantsPreview = buildParticipantsPreview($config, $docFields, $state, $currentStep);
        $stepsPreview = buildStepsPreview($stepsConfig, $state, $currentStep, $overdue);

        $item = [
            'id'                  => 'DEAL_' . $docId,
            'entity_type'         => 'deal',
            'entity_id'           => $docId,
            'title'               => $deal['TITLE'] ?? '#' . $docId,
            'entity_url'          => '/crm/deal/details/' . $docId . '/',
            'folder_url'          => $folderUrl,
            'created_at'          => $createdAt,
            'created_at_label'    => $createdAtLabel,
            'days_in_work'        => $daysInWork,
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
            'stage_color'         => $stageColor,
            'deadline_hours'      => $deadlineH,
            'hint'                => $hint,
            'participants_preview'=> $participantsPreview,
            'steps_preview'       => $stepsPreview,
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
    wsHandleThrowable('ws_process_items', $e);
}

// ── Вспомогательные функции ───────────────────────────────────────────────

function fetchActiveDeals(int $entityTypeId, int $categoryId, array $fields = []): array {
    \Bitrix\Main\Loader::includeModule('crm');
    if (empty($fields)) {
        // Безопасный fallback, если список не был передан.
        $fields = ['ID', 'TITLE', 'ASSIGNED_BY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'DATE_CREATE'];
    }
    if ($entityTypeId === 2) {
        $res = \CCrmDeal::GetListEx(
            ['DATE_CREATE' => 'DESC'],
            ['CATEGORY_ID' => $categoryId, 'CHECK_PERMISSIONS' => 'N'],
            false, ['nPageSize' => 200, 'iNumPage' => 1],
            $fields
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
        $rows[] = [
            'ID'                => $item->getId(),
            'TITLE'             => $item->getTitle(),
            'ASSIGNED_BY_ID'    => $item->getAssignedById(),
            'STAGE_ID'          => method_exists($item, 'getStageId') ? $item->getStageId() : '',
            'STAGE_SEMANTIC_ID' => $item->getStageSemanticId(),
        ];
    }
    return $rows;
}

function loadStageColorMap(int $entityTypeId, int $categoryId): array {
    $map = [];
    $entityIds = [];

    if ($entityTypeId === 2) {
        // Для сделок обычно используется DEAL_STAGE_<category>, но иногда DEAL_STAGE.
        $entityIds[] = 'DEAL_STAGE_' . $categoryId;
        $entityIds[] = 'DEAL_STAGE';
    } else {
        $entityIds[] = 'DYNAMIC_' . $entityTypeId . '_STAGE_' . $categoryId;
    }

    foreach ($entityIds as $entityId) {
        $rs = \CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => $entityId]);
        while ($st = $rs->Fetch()) {
            $statusId = (string)($st['STATUS_ID'] ?? '');
            if ($statusId === '') continue;

            $color = '';
            if (!empty($st['COLOR']) && $st['COLOR'] !== '#') {
                $color = (string)$st['COLOR'];
            } elseif (!empty($st['EXTRA']['COLOR'])) {
                $color = (string)$st['EXTRA']['COLOR'];
            }

            if ($color !== '' && !isset($map[$statusId])) {
                $map[$statusId] = $color;
            }
        }
    }

    return $map;
}

function resolveStageColorHex(array $stageColorMap, string $stageId): ?string {
    if ($stageId === '') return null;
    $color = $stageColorMap[$stageId] ?? null;
    if (!$color) return null;

    $c = trim((string)$color);
    if ($c === '') return null;

    // Нормализуем к #RRGGBB, если пришёл цвет без #.
    if ($c[0] !== '#') $c = '#' . $c;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? strtoupper($c) : null;
}

function resolveDealRootFolderIdFromRights(array $rightsJson, array $wsRoleCfg): int {
    if (!\Bitrix\Main\Loader::includeModule('disk')) return 0;

    foreach (($wsRoleCfg['folders'] ?? []) as $folderKey) {
        $diskId = (int)($rightsJson['folders'][$folderKey]['diskId'] ?? 0);
        if ($diskId <= 0) continue;

        $folder = \Bitrix\Disk\Folder::getById($diskId);
        if (!$folder) continue;

        $parentId = (int)$folder->getParentId();
        return $parentId > 0 ? $parentId : $diskId;
    }

    return 0;
}

function getFolderUrl(int $diskId): ?string {
    if (!\Bitrix\Main\Loader::includeModule('disk')) return null;
    $folder = \Bitrix\Disk\Folder::getById($diskId);
    if (!$folder) return null;
    $urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
    $path       = $urlManager->getPathInListing($folder);
    $parts      = array_filter(explode('/', trim($path, '/')));
    $encoded    = array_map('rawurlencode', $parts);
    $encoded[]  = rawurlencode($folder->getName());
    return 'https://' . $_SERVER['HTTP_HOST'] . '/' . implode('/', $encoded) . '/';
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

function buildStepsPreview(array $stepsConfig, array $state, ?string $currentStep, array $overdue): array {
    $preview = [];
    foreach ($stepsConfig as $stepKey => $stepCfg) {
        if (in_array($stepCfg['type'] ?? '', ['auto', 'final'], true)) continue;
        $stepStatus = (string)($state['stages'][$stepKey]['status'] ?? 'wait');
        $isCurrent = ($stepKey === $currentStep);
        $preview[] = [
            'key' => $stepKey,
            'label' => $stepCfg['label'] ?? $stepKey,
            'state' => $isCurrent ? 'current' : ($stepStatus === 'done' ? 'done' : 'pending'),
            'is_over' => $isCurrent && !empty($overdue['is_overdue']),
        ];
    }
    return $preview;
}
