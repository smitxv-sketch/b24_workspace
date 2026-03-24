<?php
/**
 * GET /local/api/workspace/deal_detail.php
 * Полные данные для sheet одной заявки.
 *
 * Параметры:
 *   entity_id   (required)
 *   process_key (required)
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

BpLog::registerFatalHandler('ws_deal_detail');
BpLog::init(fn(string $m) => null, BpLog::LEVEL_OFF, BpLog::LEVEL_DEBUG);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

global $USER;
if (!$USER->IsAuthorized()) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'unauthorized']); exit; }

$currentUserId = (int)$USER->GetID();
define('WS_DEBUG', !empty($_GET['debug']) && $_GET['debug'] === 'Y' && $USER->IsAdmin());

try {
    BpLog::info('ws_deal_detail', 'START', ['userId' => $currentUserId]);

    $entityId   = (int)($_GET['entity_id'] ?? 0);
    $processKey = preg_replace('/[^a-z0-9_]/', '', $_GET['process_key'] ?? '');

    if (!$entityId || !$processKey) wsJsonErr('params_required');

    // 1. Загружаем конфиги
    [$wsCfg, $config] = wsRequireProcessConfigs($processKey);

    $entityTypeId = $config['match']['entityTypeId'] ?? 2;

    // 2. Читаем документ
    \Bitrix\Main\Loader::includeModule('crm');
    $deal = \CCrmDeal::GetByID($entityId);
    if (!$deal) wsJsonErr('deal_not_found', 404);

    // 3. Читаем state и права
    $fieldCode   = getFieldCode($entityTypeId, 'json');
    $state       = BpStorage::readJson($entityTypeId, $entityId, $fieldCode);
    $rightsField = getFieldCode($entityTypeId, 'rights');
    $rightsJson  = BpStorage::readJson($entityTypeId, $entityId, $rightsField);

    $currentStep  = $state['nav']['step'] ?? null;
    $stepsConfig  = $config['steps'] ?? [];
    $circleConfig = $config['circles'] ?? [];
    $rolesConfig  = $config['roles'] ?? [];

    // 4. Роль пользователя
    $resolver     = new RoleResolver($currentUserId);
    $userRole     = $resolver->resolveRole($rolesConfig, $deal) ?? '_default';
    $wsRoleCfg    = $wsCfg['workspace_roles'][$userRole]
                 ?? $wsCfg['workspace_roles']['_default']
                 ?? ['sections'=>['alert','timeline'],'folders'=>[]];

    // 5. Версия и rework
    $version  = (int)($state['version'] ?? $state['nav']['version'] ?? 1);
    $isRework = (bool)($state['isRework'] ?? false);

    // 6. Overdue
    $overdue = $currentStep ? computeOverdue($state, $stepsConfig, $currentStep) : ['is_overdue'=>false,'overdue_hours'=>0,'elapsed_hours'=>0];

    // 7. Chips (Открыть в Б24 + основная папка сделки)
    $chips = [['label' => '↗ Открыть в Битрикс24', 'url' => '/crm/deal/details/' . $entityId . '/', 'style' => 'primary']];
    $dealRootFolderId = resolveDealRootFolderId($rightsJson, $wsRoleCfg);
    if ($dealRootFolderId > 0) {
        $dealRootUrl = getFolderUrl($dealRootFolderId);
        if ($dealRootUrl) {
            $chips[] = ['label' => '📁 Папка сделки', 'url' => $dealRootUrl, 'style' => 'default'];
        }
    }

    // 8. Alert-баннер
    $alert = buildAlert($state, $stepsConfig, $circleConfig, $rolesConfig, $deal, $currentStep, $overdue, $resolver, $entityTypeId, $entityId, $currentUserId);

    // 9. Участники
    $participants = buildParticipants($config, $deal, $state, $currentStep, $resolver);

    // 10. Таймлайн
    $timeline = buildTimeline($state, $stepsConfig, $overdue, $currentStep);

    // 11. Задачи
    $tasks = buildTasks($entityTypeId, $entityId, $currentUserId);

    // 12. Дисциплина
    $discipline = buildDiscipline($state, $stepsConfig);

    // 13. Лента событий
    $feed = buildFeed($state);

    wsJsonOk([
        'entity_id'         => $entityId,
        'process_key'       => $processKey,
        'title'             => $deal['TITLE'] ?? '#' . $entityId,
        'subtitle'          => 'Сделка #' . $entityId . ' · с ' . date('d.m.Y', strtotime($deal['DATE_CREATE'] ?? '')),
        'entity_url'        => '/crm/deal/details/' . $entityId . '/',
        'version'           => $version,
        'is_rework'         => $isRework,
        'user_process_role' => $userRole,
        'sections_to_show'  => $wsRoleCfg['sections'],
        'chips'             => $chips,
        'alert'             => $alert,
        'participants'      => $participants,
        'timeline'          => $timeline,
        'tasks'             => $tasks,
        'discipline'        => $discipline,
        'feed'              => $feed,
    ]);

} catch (\Throwable $e) {
    wsHandleThrowable('ws_deal_detail', $e);
}

// ── Построители секций ────────────────────────────────────────────────────

function buildAlert(array $state, array $stepsConfig, array $circleConfig, array $rolesConfig, array $deal, ?string $currentStep, array $overdue, RoleResolver $resolver, int $entityTypeId, int $entityId, int $currentUserId): ?array {
    // Служебные значения навигации завершённого процесса.
    if (!$currentStep || in_array($currentStep, ['_done', '_complete'], true)) {
        return [
            'type'        => 'green',
            'label'       => 'Процесс завершён',
            'title'       => 'ТКП передан в работу',
            'description' => 'Договор создан. Процесс ТКП успешно завершён.',
            'can_submit'  => false,
        ];
    }

    $stepType  = $stepsConfig[$currentStep]['type'] ?? 'human';
    $stepLabel = $stepsConfig[$currentStep]['label'] ?? $currentStep;

    // Ищем активные задания БП через прямой SQL к b_bp_task
    // (совместимо с разными версиями коробки, где CBPDocument API может отсутствовать).
    global $DB;
    $safeUserId  = (int)$currentUserId;
    $safeDocCode = $DB->ForSql(
        $entityTypeId === 2
            ? 'DEAL_' . $entityId
            : 'DYNAMIC_' . $entityTypeId . '_' . $entityId
    );

    // Читаем схему таблиц, чтобы построить SQL совместимо с версией коробки.
    $taskCols = [];
    $taskColsRs = $DB->Query("SHOW COLUMNS FROM b_bp_task");
    if ($taskColsRs) {
        while ($c = $taskColsRs->Fetch()) $taskCols[] = (string)($c['Field'] ?? '');
    }

    $taskUserCols = [];
    $taskUserColsRs = $DB->Query("SHOW COLUMNS FROM b_bp_task_user");
    if ($taskUserColsRs) {
        while ($c = $taskUserColsRs->Fetch()) $taskUserCols[] = (string)($c['Field'] ?? '');
    }

    $wfStateCols = [];
    $wfStateColsRs = $DB->Query("SHOW COLUMNS FROM b_bp_workflow_state");
    if ($wfStateColsRs) {
        while ($c = $wfStateColsRs->Fetch()) $wfStateCols[] = (string)($c['Field'] ?? '');
    }

    WsDiag::add('bp_task_table_columns', ['columns' => $taskCols], 3);
    WsDiag::add('bp_task_user_table_columns', ['columns' => $taskUserCols], 3);
    WsDiag::add('bp_workflow_state_table_columns', ['columns' => $wfStateCols], 3);

    $orderField = in_array('CREATED_DATE', $taskCols, true) ? 'CREATED_DATE' : 'ID';

    $joins = [];
    $where = ["t.STATUS = 0"];

    // USER-фильтр через b_bp_task_user (в вашей схеме USER_ID лежит там).
    if (in_array('TASK_ID', $taskUserCols, true) && in_array('USER_ID', $taskUserCols, true)) {
        $joins[] = "INNER JOIN b_bp_task_user tu ON tu.TASK_ID = t.ID";
        $where[] = "tu.USER_ID = {$safeUserId}";
    }

    // Фильтр по документу через b_bp_workflow_state.DOCUMENT_ID.
    if (in_array('ID', $wfStateCols, true) && in_array('DOCUMENT_ID', $wfStateCols, true)) {
        $joins[] = "INNER JOIN b_bp_workflow_state ws ON ws.ID = t.WORKFLOW_ID";
        $where[] = "ws.DOCUMENT_ID LIKE '%{$safeDocCode}%'";
    } elseif (in_array('DOCUMENT_NAME', $taskCols, true)) {
        // Fallback для старых схем: пытаемся зацепиться по имени документа.
        $where[] = "t.DOCUMENT_NAME LIKE '%{$safeDocCode}%'";
    }

    $sql =
        "SELECT t.ID, t.WORKFLOW_ID, t.ACTIVITY, t.ACTIVITY_NAME, t.DESCRIPTION, t.PARAMETERS
         FROM b_bp_task t
         " . implode("\n", $joins) . "
         WHERE " . implode("\n           AND ", $where) . "
         ORDER BY t.{$orderField} DESC
         LIMIT 5";

    WsDiag::add('bp_task_query_strategy', [
        'order_field' => $orderField,
        'has_task_user_join' => in_array('TASK_ID', $taskUserCols, true) && in_array('USER_ID', $taskUserCols, true),
        'has_workflow_state_join' => in_array('ID', $wfStateCols, true) && in_array('DOCUMENT_ID', $wfStateCols, true),
        'doc_code' => $safeDocCode,
    ], 2);

    $rs = $DB->Query($sql);

    $bpTasks = [];
    if ($rs) {
        while ($row = $rs->Fetch()) {
            $parameters = [];
            if (!empty($row['PARAMETERS'])) {
                $p = @unserialize($row['PARAMETERS']);
                $parameters = is_array($p) ? $p : [];
            }

            $bpTasks[] = [
                'ID'          => $row['ID'],
                'WORKFLOW_ID' => $row['WORKFLOW_ID'] ?? '',
                'NAME'        => $row['ACTIVITY_NAME'] ?? '',
                'DESCRIPTION' => $row['DESCRIPTION'] ?? '',
                'PARAMETERS'  => $parameters,
            ];
        }
    }

    if (!empty($bpTasks)) {
        $task = $bpTasks[0];

        // Очищаем BBCode из описания задания.
        $desc = $task['DESCRIPTION'] ?? '';
        $desc = preg_replace('/\[\/?(B|I|U|b|i|u)\]/', '', $desc);
        $desc = preg_replace('/\[url=[^\]]*\]([^\[]*)\[\/url\]/i', '$1', $desc);
        $desc = preg_replace('/\[[^\]]+\]/', '', $desc);
        $desc = trim($desc);

        // Заголовок берём из первой строки, остальное в description.
        $lines = array_filter(explode("\n", $desc));
        $title = array_shift($lines) ?: $stepLabel;
        $desc  = implode("\n", $lines);

        $buttons = [];
        foreach ($task['PARAMETERS']['StatusList'] ?? [] as $code => $label) {
            $isReject = in_array(strtolower($code), ['reject','decline','no','отклонить'], true);
            $buttons[] = ['code' => $code, 'label' => $label, 'style' => $isReject ? 'danger' : 'primary'];
        }

        // Формируем ссылку на активность БП.
        $workflowId  = (string)($task['WORKFLOW_ID'] ?? '');
        $activityUrl = $workflowId !== ''
            ? 'https://' . $_SERVER['HTTP_HOST'] . '/company/personal/bizproc/' . $workflowId . '/?USER_ID=' . $currentUserId
            : null;

        return [
            'type'        => $overdue['is_overdue'] ? 'red' : 'blue',
            'label'       => $overdue['is_overdue'] ? 'Просрочено' : 'Ожидает вашего ответа',
            'title'       => $title,
            'description' => $desc,
            'can_submit'  => false, // действия только через Б24
            'task_id'     => (int)($task['ID'] ?? 0),
            'buttons'     => $buttons,
            'activity_url'=> $activityUrl,
        ];
    }

    // Нет задания — формируем по типу шага и роли
    $needsAction = computeNeedsAction($currentStep, $stepsConfig, $circleConfig, $rolesConfig, $deal, $state, $resolver);

    if ($stepType === 'wait') {
        $elapsed = isset($state['stages'][$currentStep]['history'])
            ? computeElapsedDays($state['stages'][$currentStep]['history'])
            : 0;
        return [
            'type'        => 'amber',
            'label'       => 'Ожидание внешнего события' . ($elapsed > 0 ? ' · ' . $elapsed . ' д.' : ''),
            'title'       => $stepLabel,
            'description' => 'Процесс приостановлен. Уведомление придёт автоматически.',
            'can_submit'  => false,
        ];
    }

    if ($overdue['is_overdue']) {
        return [
            'type'        => 'red',
            'label'       => 'Просрочено · ' . $overdue['overdue_hours'] . ' ч сверх дедлайна',
            'title'       => ($needsAction ? 'Ожидает вашего действия: ' : '') . $stepLabel,
            'description' => $needsAction ? 'Перейдите в Битрикс24 для выполнения действия.' : 'Ожидаем другого участника.',
            'can_submit'  => false,
        ];
    }

    if ($needsAction) {
        return [
            'type'        => 'blue',
            'label'       => 'Требует вашего действия',
            'title'       => $stepLabel,
            'description' => 'Перейдите в Битрикс24 для выполнения действия.',
            'can_submit'  => false,
        ];
    }

    return [
        'type'        => 'green',
        'label'       => 'В работе',
        'title'       => 'Шаг: ' . $stepLabel,
        'description' => 'Нет активных вопросов для вас на этом шаге.',
        'can_submit'  => false,
    ];
}

function buildParticipants(array $config, array $deal, array $state, ?string $currentStep, RoleResolver $resolver): array {
    $participants = [];
    $rolesConfig  = $config['roles'] ?? [];
    $votedMap     = [];

    if ($currentStep && isset($config['circles'][$currentStep])) {
        foreach ($state['approvals'][$currentStep]['history'] ?? [] as $ev) {
            $votedMap[(int)$ev['userId']] = $ev['verdict'] ?? 'wait';
        }
    }

    foreach ($config['participants'] ?? [] as $roleKey) {
        $roleDef = $rolesConfig[$roleKey] ?? null;
        if (!$roleDef) continue;

        $userIds = $resolver->resolveToUserIds($roleDef, $deal);
        foreach ($userIds as $uid) {
            $row   = \CUser::GetByID($uid)->Fetch();
            if (!$row) continue;
            $name  = trim($row['NAME'] . ' ' . $row['LAST_NAME']);
            $verdict = $votedMap[$uid] ?? null;
            $participants[] = [
                'user_id'      => $uid,
                'name'         => $name,
                'initials'     => getInitials($name),
                'role_label'   => $roleDef['label'] ?? $roleKey,
                'avatar_color' => $verdict === 'approve' ? 'green' : ($verdict === 'reject' ? 'red' : 'blue'),
                'status'       => $verdict ?? 'active',
            ];
        }
    }

    // Согласующие из текущего круга (если есть)
    if ($currentStep && isset($config['circles'][$currentStep])) {
        foreach ($config['circles'][$currentStep]['approvers'] ?? [] as $roleKey) {
            $roleDef = $rolesConfig[$roleKey] ?? null;
            if (!$roleDef) continue;
            $userIds = $resolver->resolveToUserIds($roleDef, $deal);
            foreach ($userIds as $uid) {
                // Не дублировать
                if (array_search($uid, array_column($participants, 'user_id')) !== false) continue;
                $row = \CUser::GetByID($uid)->Fetch();
                if (!$row) continue;
                $name    = trim($row['NAME'] . ' ' . $row['LAST_NAME']);
                $verdict = $votedMap[$uid] ?? 'wait';
                $participants[] = [
                    'user_id'    => $uid, 'name' => $name, 'initials' => getInitials($name),
                    'role_label' => 'Согласующий · ' . ($verdict === 'approve' ? 'за' : ($verdict === 'reject' ? 'против' : 'ждёт')),
                    'avatar_color'=> $verdict === 'approve' ? 'green' : ($verdict === 'reject' ? 'red' : 'amber'),
                    'status'     => $verdict,
                ];
            }
        }
    }

    return $participants;
}

function buildTimeline(array $state, array $stepsConfig, array $overdue, ?string $currentStep): array {
    $timeline = [];
    foreach ($stepsConfig as $stepKey => $stepCfg) {
        $stepState = $state['stages'][$stepKey]['status'] ?? 'wait';
        $isCurrent = ($stepKey === $currentStep);

        if ($isCurrent) {
            $dotState = $overdue['is_overdue'] ? 'late' : 'current';
            $timing   = $overdue['elapsed_hours'] . ' ч · план ' . ($stepCfg['deadline_hours'] ?? '?') . ' ч';
        } elseif ($stepState === 'done') {
            $dotState = 'done';
            $timing   = buildTiming($state['stages'][$stepKey]['history'] ?? [], $stepCfg['deadline_hours'] ?? null);
        } else {
            $dotState = 'pending';
            $timing   = null;
        }

        // Голоса в круге (для текущего шага)
        $voters = [];
        if ($isCurrent && isset($state['approvals'][$stepKey])) {
            foreach ($state['approvals'][$stepKey]['history'] ?? [] as $ev) {
                $voters[] = [
                    'name'    => $ev['userName'] ?? 'Участник',
                    'verdict' => $ev['verdict'] ?? 'wait',
                    'label'   => $ev['verdict'] === 'approve' ? 'за' : ($ev['verdict'] === 'reject' ? 'против' : 'ждёт'),
                ];
            }
        }

        $row = [
            'step_key'      => $stepKey,
            'label'         => $stepCfg['label'] ?? $stepKey,
            'state'         => $dotState,
            'timing'        => $timing,
            'is_overdue'    => $isCurrent && $overdue['is_overdue'],
            'overdue_label' => $isCurrent && $overdue['is_overdue'] ? 'просрочено' : null,
            'voters'        => $voters,
        ];

        if ($stepCfg['type'] === 'final') continue; // не показываем финальный шаг
        $timeline[] = $row;
    }
    return $timeline;
}

function buildTiming(array $history, ?int $deadlineH): ?string {
    $startTs = null; $endTs = null;
    foreach ($history as $ev) {
        if ($ev['status'] === 'work' && !$startTs) $startTs = strtotime($ev['date'] ?? '');
        if (in_array($ev['status'], ['done','fail'], true)) $endTs = strtotime($ev['date'] ?? '');
    }
    if (!$startTs) return null;
    $elapsed = round(($endTs ?: time()) - $startTs) / 3600;
    $s = round($elapsed, 1) . ' ч';
    if ($deadlineH) $s .= ' · план ' . $deadlineH . ' ч';
    if ($endTs) $s .= ' · ' . date('d.m', $endTs);
    return $s;
}

function computeElapsedDays(array $history): int {
    foreach (array_reverse($history) as $ev) {
        if ($ev['status'] === 'work') {
            return (int)round((time() - strtotime($ev['date'] ?? '')) / 86400);
        }
    }
    return 0;
}

function buildTasks(int $entityTypeId, int $entityId, int $currentUserId): array {
    \Bitrix\Main\Loader::includeModule('tasks');
    $crmBinding = $entityTypeId === 2 ? 'D_' . $entityId : 'T' . dechex($entityTypeId) . '_' . $entityId;
    $res = \CTasks::GetList(
        ['CREATED_DATE' => 'DESC'],
        ['UF_CRM_TASK' => $crmBinding, 'REAL_STATUS' => [\CTasks::STATE_PENDING, \CTasks::STATE_IN_PROGRESS, \CTasks::STATE_SUPPOSEDLY_COMPLETED]],
        ['ID','TITLE','STATUS','RESPONSIBLE_ID','CREATED_BY','DEADLINE','TIME_SPENT_IN_LOGS']
    );
    $tasks = [];
    while ($task = $res->Fetch()) {
        $status = (int)$task['STATUS'];
        $state  = $status === \CTasks::STATE_COMPLETED ? 'done'
                : ($status === \CTasks::STATE_SUPPOSEDLY_COMPLETED ? 'review' : 'open');
        $tag    = null;
        if ($state === 'review' && (int)$task['CREATED_BY'] === $currentUserId) $tag = 'Ждёт вашей проверки — откройте в Б24';
        $factH  = round((int)$task['TIME_SPENT_IN_LOGS'] / 3600, 1);
        $tasks[] = [
            'id'          => (int)$task['ID'],
            'title'       => $task['TITLE'],
            'state'       => $state,
            'meta'        => $factH > 0 ? $factH . ' ч факт' : '',
            'tag'         => $tag,
            'url'         => '/company/personal/user/' . $task['RESPONSIBLE_ID'] . '/tasks/task/view/' . $task['ID'] . '/',
        ];
    }
    return $tasks;
}

function buildDiscipline(array $state, array $stepsConfig): array {
    $rows = [];
    foreach ($stepsConfig as $stepKey => $stepCfg) {
        if (($stepCfg['type'] ?? '') === 'final') continue;
        $planH   = $stepCfg['deadline_hours'] ?? null;
        if (!$planH) continue;
        $history = $state['stages'][$stepKey]['history'] ?? [];
        $startTs = null; $endTs = null;
        foreach ($history as $ev) {
            if ($ev['status'] === 'work' && !$startTs) $startTs = strtotime($ev['date'] ?? '');
            if (in_array($ev['status'], ['done','fail'], true)) $endTs = strtotime($ev['date'] ?? '');
        }
        if (!$startTs) continue;
        $factH  = round(($endTs ?: time()) - $startTs) / 3600;
        $isRunning = !$endTs;
        $stateKey  = $isRunning ? 'running' : ($factH > $planH ? 'overdue' : 'ok');
        $rows[] = [
            'step_label'  => $stepCfg['label'] ?? $stepKey,
            'plan_hours'  => $planH,
            'fact_hours'  => round($factH, 1),
            'state'       => $stateKey,
            'delta'       => $isRunning ? null : round($factH - $planH, 1),
        ];
    }
    return $rows;
}

function buildFeed(array $state): array {
    $events = [];
    foreach ($state['nav']['history'] ?? [] as $ev) {
        $events[] = ['type'=>'nav','date_ts'=>strtotime($ev['date']??''),'text'=>'Переход: '.($ev['from']??'').' → '.($ev['to']??''),'initials'=>'БП','color'=>'system'];
    }
    foreach ($state['stages'] ?? [] as $stepKey => $stageData) {
        foreach ($stageData['history'] ?? [] as $ev) {
            if (!in_array($ev['status']??'', ['work','done','fail'], true)) continue;
            $un = $ev['userName'] ?? '';
            $events[] = ['type'=>'stage','date_ts'=>strtotime($ev['date']??''),'text'=>($un?$un.' — ':'').match($ev['status']??''){'done'=>'Завершён: ','fail'=>'Отклонён: ',default=>'Начат: '}. ($stageData['title']??$stepKey),'initials'=>$un?getInitials($un):'БП','color'=>match($ev['status']??''){'done'=>'green','fail'=>'red',default=>'system'}];
        }
    }
    foreach ($state['approvals'] ?? [] as $circleKey => $circleData) {
        foreach ($circleData['history'] ?? [] as $ev) {
            $un = $ev['userName'] ?? '';
            $events[] = ['type'=>'approval','date_ts'=>strtotime($ev['date']??''),'text'=>$un.' '.($ev['verdict']==='approve'?'согласовал':'отклонил').' (v'.($ev['version']??1).')'.(isset($ev['comment'])&&$ev['comment']?': '.$ev['comment']:''),'initials'=>getInitials($un),'color'=>$ev['verdict']==='approve'?'green':'red'];
        }
    }
    usort($events, fn($a,$b) => $b['date_ts'] <=> $a['date_ts']);
    return array_slice($events, 0, 20);
}

function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0],0,1).mb_substr($parts[1],0,1));
    return mb_strtoupper(mb_substr($name,0,2));
}

function getFolderUrl(int $diskId): ?string {
    if (!\Bitrix\Main\Loader::includeModule('disk')) return null;
    $folder = \Bitrix\Disk\Folder::getById($diskId);
    if (!$folder) return null;
    $urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
    $path       = $urlManager->getPathInListing($folder);
    $parts      = array_filter(explode('/', trim($path,'/')));
    $encoded    = array_map('rawurlencode', $parts);
    $encoded[]  = rawurlencode($folder->getName());
    return 'https://' . $_SERVER['HTTP_HOST'] . '/' . implode('/', $encoded) . '/';
}

function resolveDealRootFolderId(array $rightsJson, array $wsRoleCfg): int {
    if (!\Bitrix\Main\Loader::includeModule('disk')) return 0;

    // Берём первый доступный folder key из роли и ищем его parent (на уровень выше).
    foreach (($wsRoleCfg['folders'] ?? []) as $folderKey) {
        $diskId = (int)($rightsJson['folders'][$folderKey]['diskId'] ?? 0);
        if ($diskId <= 0) continue;

        $folder = \Bitrix\Disk\Folder::getById($diskId);
        if (!$folder) continue;

        $parentId = (int)$folder->getParentId();
        if ($parentId > 0) return $parentId;
        return $diskId;
    }

    return 0;
}
