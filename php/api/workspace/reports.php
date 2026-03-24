<?php
/**
 * GET /local/ws/api/workspace/reports.php
 * Отчёты по трудозатратам.
 *
 * Параметры:
 *   report            (required) time_tracking
 *   dept_id           (optional) ID отдела
 *   include_subdepts  (optional) Y|N (default Y)
 *   group_mode        (optional) users_only|users_projects|projects_only
 *   period_preset     (optional) today|7d|30d|month|quarter|custom
 *   date_from/date_to (optional) YYYY-MM-DD for custom
 *   sort_by           (optional) hours_desc|hours_asc|name_asc|name_desc
 *   format            (optional) json|csv
 *   schema_debug      (optional) Y|N - вернуть структуру таблиц задач
 *   data_debug        (optional) Y|N - вернуть примеры строк задач/elapsed
 *   expand_dept       (optional) ID отдела для UI-раскрытия
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once __DIR__ . '/_paths.php';
require_once wsDocPath('/bitrix/modules/main/include/prolog_before.php');
require_once wsDocPath(wsBprocLibRoot() . '/BpLog.php');
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/reports/bootstrap.php';
require_once __DIR__ . '/reports/period.php';
require_once __DIR__ . '/reports/filters.php';
require_once __DIR__ . '/reports/tasks_source.php';
require_once __DIR__ . '/reports/planning_view.php';
require_once __DIR__ . '/reports/heatmap_view.php';
require_once __DIR__ . '/reports/response.php';

BpLog::registerFatalHandler('ws_reports');
BpLog::init(fn(string $m) => null, BpLog::LEVEL_OFF, BpLog::LEVEL_DEBUG);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

global $USER;
if (!$USER->IsAuthorized()) { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'unauthorized']); exit; }

$currentUserId = (int)$USER->GetID();
define('WS_DEBUG', !empty($_GET['debug']) && $_GET['debug'] === 'Y' && $USER->IsAdmin());

try {
    $req = wsReportsReadRequest();
    $report = (string)$req['report'];
    if ($report !== 'time_tracking') wsJsonErr('report_not_supported', 400);

    \Bitrix\Main\Loader::includeModule('intranet');
    \Bitrix\Main\Loader::includeModule('tasks');
    \Bitrix\Main\Loader::includeModule('socialnetwork');
    \Bitrix\Main\Loader::includeModule('iblock');

    $includeSubdepts = (bool)$req['include_subdepts'];
    $groupMode = (string)$req['group_mode'];
    $sortBy = (string)$req['sort_by'];
    $periodPreset = (string)$req['period_preset'];
    $format = (string)$req['format'];
    $schemaDebug = (bool)$req['schema_debug'];
    $dataDebug = (bool)$req['data_debug'];
    $view = (string)$req['view'];
    $planningScale = (string)$req['planning_scale'];
    $heatmapEnabled = strtoupper((string)($_GET['ws_heatmap'] ?? 'N')) === 'Y';

    $period = wsResolveReportPeriod(
        $periodPreset,
        (string)$req['date_from'],
        (string)$req['date_to']
    );
    $dateFilters = wsReadTaskDateFilters($_GET);

    // Полный список доступных отделов нужен для проверки прав на запрошенный dept_id.
    $allowedDeptIdsAll = wsGetAllowedDepartmentIds($currentUserId, true);
    if (empty($allowedDeptIdsAll)) wsJsonErr('no_allowed_departments', 403);

    $requestedDeptId = (int)$req['requested_dept_id'];
    $selectedDeptId = $requestedDeptId > 0 ? $requestedDeptId : (int)$allowedDeptIdsAll[0];
    if (!in_array($selectedDeptId, $allowedDeptIdsAll, true)) {
        WsDiag::add('reports_forbidden_dept_fallback', [
            'requested_dept_id' => $selectedDeptId,
            'allowed_depts' => $allowedDeptIdsAll,
            'fallback_dept_id' => (int)$allowedDeptIdsAll[0],
        ], 1);
        $selectedDeptId = (int)$allowedDeptIdsAll[0];
    }

    $expandDept = (int)$req['expand_dept'];
    WsDiag::add('reports_request', [
        'report' => $report,
        'selected_dept' => $selectedDeptId,
        'include_subdepts' => $includeSubdepts ? 'Y' : 'N',
        'group_mode' => $groupMode,
        'sort_by' => $sortBy,
        'period_preset' => $periodPreset,
        'expand_dept' => $expandDept,
        'period' => $period,
        'date_filters' => $dateFilters,
        'allowed_depts' => $allowedDeptIdsAll,
        'schema_debug' => $schemaDebug ? 'Y' : 'N',
        'data_debug' => $dataDebug ? 'Y' : 'N',
        'view' => $view,
        'planning_scale' => $planningScale,
        'heatmap_enabled' => $heatmapEnabled ? 'Y' : 'N',
    ], 1);

    $deptScope = wsResolveDeptScope($selectedDeptId, $includeSubdepts, $allowedDeptIdsAll);
    $deptTreeIds = $includeSubdepts ? $allowedDeptIdsAll : [$selectedDeptId];
    $deptTree = wsBuildDepartmentTree($deptTreeIds, $selectedDeptId);
    WsDiag::add('reports_dept_debug', [
        'user_id' => $currentUserId,
        'allowed_count' => count($allowedDeptIdsAll),
        'selected_dept_id' => $selectedDeptId,
        'include_subdepts' => $includeSubdepts ? 'Y' : 'N',
        'scope_count' => count($deptScope),
        'tree_root_count' => count($deptTree),
    ], 1);
    $taskFieldMap = wsDetectTaskFieldMap();
    $reportData = wsBuildTimeTrackingReport([
        'dept_scope' => $deptScope,
        'date_from' => $period['from'],
        'date_to' => $period['to'],
        'group_mode' => $groupMode,
        'sort_by' => $sortBy,
        'task_field_map' => $taskFieldMap,
        'date_filters' => $dateFilters,
    ]);

    if ($format === 'csv') {
        wsSendCsvReport($reportData['csv_rows'], $period, $selectedDeptId);
        exit;
    }

    $planning = null;
    $heatmap = null;
    if ($view === 'planning') {
        $planning = wsBuildPlanningView([
            'selected_dept_id' => $selectedDeptId,
            'dept_scope' => $deptScope,
            'planning_scale' => $planningScale,
            'date_filters' => $dateFilters,
            'task_field_map' => $taskFieldMap,
        ]);
    } elseif ($view === 'heatmap' && $heatmapEnabled) {
        $heatmap = wsBuildHeatmapView([
            'selected_dept_id' => $selectedDeptId,
            'dept_scope' => $deptScope,
            'planning_scale' => $planningScale,
        ]);
    }

    $schema = null;
    if ($schemaDebug) {
        $schema = wsCollectTasksSchema();
        WsDiag::add('reports_schema_debug', $schema, 1);
    }
    $dataDebugPayload = null;
    if ($dataDebug) {
        $dataDebugPayload = wsCollectTasksDataDebug($deptScope, $period['from'], $period['to'], $dateFilters);
        WsDiag::add('reports_data_debug', [
            'tasks_rows' => count((array)($dataDebugPayload['tasks_sample'] ?? [])),
            'elapsed_rows' => count((array)($dataDebugPayload['elapsed_sample'] ?? [])),
        ], 1);
    }

    wsJsonOk(wsBuildReportsResponse([
        'period' => $period,
        'group_mode' => $groupMode,
        'include_subdepts' => $includeSubdepts,
        'sort_by' => $sortBy,
        'date_filters' => $dateFilters,
        'view' => $view,
        'planning_scale' => $planningScale,
        'selected_dept_id' => $selectedDeptId,
        'expand_dept' => $expandDept,
        'dept_tree' => $deptTree,
        'report_data' => $reportData,
        'task_field_map' => $taskFieldMap,
        'schema' => $schema,
        'data_debug_payload' => $dataDebugPayload,
        'planning' => $planning,
        'heatmap' => $heatmap,
    ]));
} catch (\Throwable $e) {
    wsHandleThrowable('ws_reports', $e);
}

function wsResolveReportPeriod(string $preset, string $from, string $to): array {
    $preset = strtolower(trim($preset));
    $today = new \DateTimeImmutable('today');

    switch ($preset) {
        case 'today':
            $fromDate = $today->format('Y-m-d');
            $toDate = $today->format('Y-m-d');
            break;
        case '7d':
            $fromDate = $today->modify('-6 days')->format('Y-m-d');
            $toDate = $today->format('Y-m-d');
            break;
        case '30d':
            $fromDate = $today->modify('-29 days')->format('Y-m-d');
            $toDate = $today->format('Y-m-d');
            break;
        case 'quarter':
            $month = (int)$today->format('n');
            $quarterStartMonth = (int)(floor(($month - 1) / 3) * 3 + 1);
            $start = new \DateTimeImmutable($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $fromDate = $start->format('Y-m-d');
            $toDate = $today->format('Y-m-d');
            break;
        case 'custom':
            $hasFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1;
            $hasTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;
            if (!$hasFrom && !$hasTo) {
                // Без "главной даты": если период не задан, берём полный диапазон.
                $fromDate = '2000-01-01';
                $toDate = $today->format('Y-m-d');
            } else {
                $fromDate = $hasFrom ? $from : '2000-01-01';
                $toDate = $hasTo ? $to : $today->format('Y-m-d');
            }
            break;
        case 'month':
        default:
            $preset = 'month';
            $fromDate = date('Y-m-01');
            $toDate = date('Y-m-t');
            break;
    }

    if ($fromDate > $toDate) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }

    $months = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    $label = $months[(int)date('n', strtotime($fromDate)) - 1] . ' ' . date('Y', strtotime($fromDate));
    if ($preset === 'custom' && $fromDate === '2000-01-01' && $toDate === $today->format('Y-m-d')) {
        $label = 'Все время';
    } elseif (substr($fromDate, 0, 7) !== substr($toDate, 0, 7)) {
        $label = $fromDate . ' — ' . $toDate;
    }

    return ['from' => $fromDate, 'to' => $toDate, 'label' => $label, 'preset' => $preset];
}

function wsGetAllowedDepartmentIds(int $userId, bool $includeSubdepts): array {
    $allowed = [];
    $userDepts = array_map('intval', (array)\CIntranetUtils::GetUserDepartments($userId));
    if (empty($userDepts)) {
        // Fallback: читаем отделы напрямую из профиля пользователя.
        $u = \CUser::GetByID($userId)->Fetch();
        $userDepts = array_map('intval', (array)($u['UF_DEPARTMENT'] ?? []));
    }

    $managedDepts = wsGetManagedDepartmentIds($userId);
    $baseDepts = array_values(array_unique(array_merge($userDepts, $managedDepts)));

    foreach ($baseDepts as $deptId) {
        if ($deptId > 0) $allowed[$deptId] = true;
        if ($includeSubdepts) {
            foreach (wsGetDepartmentDescendants($deptId) as $childId) {
                $allowed[$childId] = true;
            }
        }
    }

    $ids = array_map('intval', array_keys($allowed));
    sort($ids);
    WsDiag::add('reports_allowed_depts', [
        'user_id' => $userId,
        'include_subdepts' => $includeSubdepts ? 'Y' : 'N',
        'user_depts' => $userDepts,
        'managed_depts' => $managedDepts,
        'base_depts' => $baseDepts,
        'allowed' => $ids,
    ], 2);
    return $ids;
}

function wsGetManagedDepartmentIds(int $userId): array {
    if ($userId <= 0 || !class_exists('\CIBlockSection')) return [];
    $managed = [];
    $res = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['ID', 'UF_HEAD']
    );
    while ($row = $res->Fetch()) {
        $heads = array_map('intval', (array)($row['UF_HEAD'] ?? []));
        if (in_array($userId, $heads, true)) {
            $id = (int)($row['ID'] ?? 0);
            if ($id > 0) $managed[$id] = true;
        }
    }
    return array_values(array_map('intval', array_keys($managed)));
}

function wsGetDepartmentDescendants(int $rootDeptId): array {
    if ($rootDeptId <= 0) return [];
    if (!class_exists('\CIBlockSection')) {
        WsDiag::add('reports_departments_error', ['reason' => 'CIBlockSection_not_found'], 1);
        return [];
    }
    $childrenByParent = [];
    $all = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['ID', 'IBLOCK_SECTION_ID']
    );
    while ($row = $all->Fetch()) {
        $id = (int)$row['ID'];
        $parentId = (int)($row['IBLOCK_SECTION_ID'] ?? 0);
        if ($id <= 0) continue;
        if (!isset($childrenByParent[$parentId])) $childrenByParent[$parentId] = [];
        $childrenByParent[$parentId][] = $id;
    }

    $desc = [];
    $queue = [$rootDeptId];
    while (!empty($queue)) {
        $parent = (int)array_shift($queue);
        foreach (($childrenByParent[$parent] ?? []) as $childId) {
            if ($childId > 0 && !isset($desc[$childId])) {
                $desc[$childId] = true;
                $queue[] = $childId;
            }
        }
    }
    unset($desc[$rootDeptId]);
    return array_values(array_map('intval', array_keys($desc)));
}

function wsResolveDeptScope(int $selectedDeptId, bool $includeSubdepts, array $allowedDeptIds): array {
    $scope = [$selectedDeptId];
    if ($includeSubdepts) {
        $scope = array_merge($scope, wsGetDepartmentDescendants($selectedDeptId));
    }
    $scope = array_values(array_unique(array_map('intval', $scope)));
    $allowedMap = array_fill_keys(array_map('intval', $allowedDeptIds), true);
    return array_values(array_filter($scope, static fn(int $id): bool => isset($allowedMap[$id])));
}

function wsBuildDepartmentTree(array $allowedDeptIds, int $selectedDeptId): array {
    if (empty($allowedDeptIds)) return [];
    if (!class_exists('\CIBlockSection')) {
        WsDiag::add('reports_departments_error', ['reason' => 'CIBlockSection_not_found_tree'], 1);
        $flat = [];
        foreach ($allowedDeptIds as $id) {
            $flat[] = [
                'id' => (int)$id,
                'name' => 'Отдел #' . (int)$id,
                'is_current' => (int)$id === (int)$selectedDeptId,
                'children' => [],
            ];
        }
        return $flat;
    }

    $items = [];
    $res = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['ID' => $allowedDeptIds, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['ID', 'NAME', 'IBLOCK_SECTION_ID']
    );
    while ($row = $res->Fetch()) {
        $id = (int)$row['ID'];
        $items[$id] = [
            'id' => $id,
            'name' => (string)$row['NAME'],
            'parent_id' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
            'is_current' => $id === $selectedDeptId,
            'children' => [],
        ];
    }

    $roots = [];
    foreach ($items as $id => &$node) {
        $parent = (int)$node['parent_id'];
        if ($parent > 0 && isset($items[$parent])) {
            $items[$parent]['children'][] = &$node;
        } else {
            $roots[] = &$node;
        }
    }
    unset($node);

    $normalize = function(array $n) use (&$normalize): array {
        return [
            'id' => $n['id'],
            'name' => $n['name'],
            'is_current' => $n['is_current'],
            'children' => array_map($normalize, $n['children']),
        ];
    };

    return array_map($normalize, $roots);
}

function wsBuildTimeTrackingReport(array $options): array {
    global $DB;

    $deptIds = array_map('intval', (array)($options['dept_scope'] ?? []));
    if (empty($deptIds)) {
        return ['rows' => [], 'totals' => ['total_hours' => 0, 'total_salary' => 0], 'csv_rows' => []];
    }
    $dateFrom = (string)$options['date_from'];
    $dateTo = (string)$options['date_to'];
    $groupMode = (string)($options['group_mode'] ?? 'users_projects');
    $sortBy = (string)($options['sort_by'] ?? 'hours_desc');
    $taskFieldMap = (array)($options['task_field_map'] ?? []);
    $dateFilters = (array)($options['date_filters'] ?? []);

    $users = [];
    $uRes = \CUser::GetList(
        'LAST_NAME',
        'ASC',
        ['UF_DEPARTMENT' => $deptIds, 'ACTIVE' => 'Y'],
        ['FIELDS' => ['ID', 'NAME', 'LAST_NAME'], 'SELECT' => ['UF_DEPARTMENT', 'UF_HOURLY_RATE']]
    );
    while ($u = $uRes->Fetch()) {
        $uid = (int)$u['ID'];
        $deptList = array_map('intval', (array)($u['UF_DEPARTMENT'] ?? []));
        $deptId = (int)($deptList[0] ?? 0);
        $users[$uid] = [
            'user_id' => $uid,
            'user_name' => trim((string)$u['NAME'] . ' ' . (string)$u['LAST_NAME']),
            'dept_name' => wsGetDeptNameById($deptId),
            'dept_id' => $deptId,
            'hourly_rate' => (float)($u['UF_HOURLY_RATE'] ?? 0),
            'total_hours' => 0.0,
            'salary' => 0.0,
            'projects' => [],
            'tasks' => [],
        ];
    }
    if (empty($users)) {
        return ['rows' => [], 'totals' => ['total_hours' => 0, 'total_salary' => 0], 'csv_rows' => []];
    }

    $userIdsSql = implode(',', array_map('intval', array_keys($users)));
    $safeFrom = $DB->ForSql($dateFrom . ' 00:00:00');
    $safeTo = $DB->ForSql($dateTo . ' 23:59:59');

    $hasTasksWorkgroup = wsTableExists('b_tasks_workgroup');
    $hasTaskGroupColumn = wsColumnExists('b_tasks', 'GROUP_ID');
    $projectJoinSql = '';
    $projectIdExpr = '0';
    if ($hasTasksWorkgroup) {
        $projectJoinSql = "LEFT JOIN b_tasks_workgroup tg ON tg.TASK_ID = t.ID";
        $projectIdExpr = 'tg.GROUP_ID';
    } elseif ($hasTaskGroupColumn) {
        $projectIdExpr = 't.GROUP_ID';
    }
    WsDiag::add('reports_project_strategy', [
        'has_b_tasks_workgroup' => $hasTasksWorkgroup,
        'has_b_tasks_GROUP_ID' => $hasTaskGroupColumn,
        'project_id_expr' => $projectIdExpr,
    ], 1);

    $statusExpr = !empty($taskFieldMap['status']) ? 'MAX(t.STATUS)' : 'NULL';
    $createdExpr = !empty($taskFieldMap['created_date']) ? 'MAX(t.CREATED_DATE)' : 'NULL';
    $closedExpr = !empty($taskFieldMap['closed_date']) ? 'MAX(t.CLOSED_DATE)' : 'NULL';
    $deadlineExpr = !empty($taskFieldMap['deadline']) ? 'MAX(t.DEADLINE)' : 'NULL';
    $planStartExpr = !empty($taskFieldMap['plan_start']) ? 'MAX(t.START_DATE_PLAN)' : 'NULL';
    $planEndExpr = !empty($taskFieldMap['plan_end']) ? 'MAX(t.END_DATE_PLAN)' : 'NULL';
    $timeEstimateExpr = !empty($taskFieldMap['time_estimate']) ? 'MAX(t.TIME_ESTIMATE)' : 'NULL';
    $taskDateWhereSql = wsBuildTaskDateWhereSql('t', $dateFilters);

    $sql = "
        SELECT
            te.USER_ID                               AS user_id,
            t.ID                                     AS task_id,
            t.TITLE                                  AS task_name,
            {$projectIdExpr}                         AS project_id,
            sg.NAME                                  AS project_name,
            DATE(te.CREATED_DATE)                    AS elapsed_date,
            ROUND(SUM(te.MINUTES) / 60, 2)           AS hours,
            {$statusExpr}                            AS task_status,
            {$createdExpr}                           AS task_created_date,
            {$closedExpr}                            AS task_closed_date,
            {$deadlineExpr}                          AS task_deadline,
            {$planStartExpr}                         AS task_plan_start,
            {$planEndExpr}                           AS task_plan_end,
            {$timeEstimateExpr}                      AS task_time_estimate
        FROM b_tasks_elapsed_time te
        INNER JOIN b_tasks t ON te.TASK_ID = t.ID
        {$projectJoinSql}
        LEFT JOIN b_sonet_group sg ON sg.ID = {$projectIdExpr}
        WHERE te.CREATED_DATE >= '{$safeFrom}'
          AND te.CREATED_DATE <= '{$safeTo}'
          AND te.USER_ID IN ({$userIdsSql})
          {$taskDateWhereSql}
        GROUP BY te.USER_ID, t.ID, project_id, elapsed_date
        ORDER BY te.USER_ID ASC, sg.NAME ASC, t.TITLE ASC
    ";
    WsDiag::add('reports_sql', ['sql' => $sql], 3);

    $rs = $DB->Query($sql);
    $csvRows = [];
    while ($row = $rs->Fetch()) {
        $uid = (int)$row['user_id'];
        if (!isset($users[$uid])) continue;

        $projectId = (int)($row['project_id'] ?? 0);
        $projectKey = $projectId > 0 ? 'P' . $projectId : 'NO_PROJECT';
        if (!isset($users[$uid]['projects'][$projectKey])) {
            $users[$uid]['projects'][$projectKey] = [
                'project_id' => $projectId > 0 ? $projectId : null,
                'project_name' => $projectId > 0 ? (string)($row['project_name'] ?: ('Проект #' . $projectId)) : 'Без проекта',
                'hours' => 0.0,
                'tasks' => [],
            ];
        }

        $hours = (float)$row['hours'];
        $planHoursRaw = isset($row['task_time_estimate']) && $row['task_time_estimate'] !== null
            ? round(((float)$row['task_time_estimate']) / 3600, 2)
            : null;
        $planHours = ($planHoursRaw !== null && $planHoursRaw > 0) ? $planHoursRaw : null;
        $taskStatusCode = isset($row['task_status']) ? (int)$row['task_status'] : null;
        $taskStatusLabel = wsTaskStatusLabel($taskStatusCode);
        $createdDate = wsNormalizeDate((string)($row['task_created_date'] ?? ''));
        $closedDate = wsNormalizeDate((string)($row['task_closed_date'] ?? ''));
        $deadline = wsNormalizeDate((string)($row['task_deadline'] ?? ''));
        $planStart = wsNormalizeDate((string)($row['task_plan_start'] ?? ''));
        $planEnd = wsNormalizeDate((string)($row['task_plan_end'] ?? ''));
        $isOverdue = false;
        if ($deadline) {
            $isOverdue = (strtotime($deadline . ' 23:59:59') < time()) && !in_array($taskStatusCode, [5], true);
        }

        $users[$uid]['projects'][$projectKey]['hours'] += $hours;
        $users[$uid]['projects'][$projectKey]['tasks'][] = [
            'task_id' => (int)$row['task_id'],
            'task_name' => (string)$row['task_name'],
            'hours' => round($hours, 2),
            'date' => (string)$row['elapsed_date'],
            'created_date' => $createdDate,
            'closed_date' => $closedDate,
            'status_code' => $taskStatusCode,
            'status_label' => $taskStatusLabel,
            'deadline' => $deadline,
            'plan_start' => $planStart,
            'plan_end' => $planEnd,
            'plan_hours' => $planHours,
            'fact_hours' => round($hours, 2),
            'variance_hours' => $planHours !== null ? round($hours - $planHours, 2) : null,
            'is_overdue' => $isOverdue,
        ];
        $users[$uid]['tasks'][] = [
            'task_id' => (int)$row['task_id'],
            'task_name' => (string)$row['task_name'],
            'project_id' => $projectId > 0 ? $projectId : null,
            'project_name' => $projectId > 0 ? (string)($row['project_name'] ?: ('Проект #' . $projectId)) : 'Без проекта',
            'hours' => round($hours, 2),
            'date' => (string)$row['elapsed_date'],
            'created_date' => $createdDate,
            'closed_date' => $closedDate,
            'status_code' => $taskStatusCode,
            'status_label' => $taskStatusLabel,
            'deadline' => $deadline,
            'plan_start' => $planStart,
            'plan_end' => $planEnd,
            'plan_hours' => $planHours,
            'fact_hours' => round($hours, 2),
            'variance_hours' => $planHours !== null ? round($hours - $planHours, 2) : null,
            'is_overdue' => $isOverdue,
        ];
        $users[$uid]['total_hours'] += $hours;
        $csvRows[] = [
            'dept' => $users[$uid]['dept_name'],
            'project' => $projectId > 0 ? (string)($row['project_name'] ?: ('Проект #' . $projectId)) : 'Без проекта',
            'user' => $users[$uid]['user_name'],
            'task' => (string)$row['task_name'],
            'date' => (string)$row['elapsed_date'],
            'hours' => round($hours, 2),
            'rate' => (float)$users[$uid]['hourly_rate'],
            'amount' => round($hours * (float)$users[$uid]['hourly_rate'], 2),
        ];
    }

    $rows = [];
    $projects = [];
    $totalHours = 0.0;
    $totalSalary = 0.0;
    foreach ($users as $u) {
        $u['projects'] = array_values(array_map(function(array $p): array {
            $p['hours'] = round($p['hours'], 2);
            return $p;
        }, $u['projects']));

        $u['total_hours'] = round($u['total_hours'], 2);
        $u['salary'] = round($u['total_hours'] * (float)$u['hourly_rate'], 2);
        $rows[] = $u;

        foreach ($u['projects'] as $p) {
            $pKey = (string)($p['project_id'] ?? 'NO_PROJECT');
            if (!isset($projects[$pKey])) {
                $projects[$pKey] = [
                    'project_id' => $p['project_id'],
                    'project_name' => $p['project_name'],
                    'hours' => 0.0,
                    'users' => [],
                ];
            }
            $projects[$pKey]['hours'] += (float)$p['hours'];
            $projects[$pKey]['users'][] = [
                'user_id' => $u['user_id'],
                'user_name' => $u['user_name'],
                'hours' => (float)$p['hours'],
                'tasks' => $p['tasks'],
            ];
        }
        $totalHours += $u['total_hours'];
        $totalSalary += $u['salary'];
    }

    $rowsUsers = wsSortUsersRows($rows, $sortBy);
    $currentWorkRows = wsBuildCurrentWorkRows($users, $dateFrom, $dateTo, $taskFieldMap, $dateFilters);
    $slices = wsBuildReportSlices($rowsUsers, $currentWorkRows);
    $rows = $rowsUsers;
    if ($groupMode === 'users_only') {
        $rows = array_map(static function(array $u): array {
            $u['projects'] = [];
            return $u;
        }, $rows);
    } elseif ($groupMode === 'projects_only') {
        $projectRows = array_values($projects);
        usort($projectRows, static fn(array $a, array $b): int => ($b['hours'] <=> $a['hours']));
        $rows = array_values(array_map(static function(array $p): array {
            $p['hours'] = round((float)$p['hours'], 2);
            return $p;
        }, $projectRows));
    }

    WsDiag::add('reports_rows_built', ['users' => count($rows), 'total_hours' => $totalHours, 'total_salary' => $totalSalary], 1);
    return [
        'rows' => $rows,
        'totals' => [
            'total_hours' => round($totalHours, 2),
            'total_salary' => round($totalSalary, 2),
            'users_count' => count($users),
            'avg_hours_per_user' => count($users) > 0 ? round($totalHours / count($users), 2) : 0.0,
        ],
        'current_work' => $slices['current_work'],
        'period_done' => $slices['period_done'],
        'period_in_progress' => $slices['period_in_progress'],
        'status_summary' => $slices['status_summary'],
        'csv_rows' => $csvRows,
    ];
}

function wsSortUsersRows(array $rows, string $sortBy): array {
    usort($rows, static function(array $a, array $b) use ($sortBy): int {
        switch ($sortBy) {
            case 'hours_asc':
                return ($a['total_hours'] <=> $b['total_hours']);
            case 'name_desc':
                return strcmp((string)$b['user_name'], (string)$a['user_name']);
            case 'name_asc':
                return strcmp((string)$a['user_name'], (string)$b['user_name']);
            case 'hours_desc':
            default:
                return ($b['total_hours'] <=> $a['total_hours']);
        }
    });
    return $rows;
}

function wsGetDeptNameById(int $deptId): string {
    if ($deptId <= 0) return '';
    if (!class_exists('\CIBlockSection')) {
        return 'Отдел #' . $deptId;
    }
    $row = \CIBlockSection::GetByID($deptId)->Fetch();
    return (string)($row['NAME'] ?? ('Отдел #' . $deptId));
}

function wsSendCsvReport(array $rows, array $period, int $selectedDeptId): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_tracking_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Период', 'Отдел', 'Проект', 'Сотрудник', 'Задача', 'Дата', 'Часы', 'Ставка', 'Сумма'], ';');
    $periodLabel = (string)($period['label'] ?? (($period['from'] ?? '') . ' — ' . ($period['to'] ?? '')));
    $dept = wsGetDeptNameById($selectedDeptId);
    foreach ($rows as $r) {
        fputcsv($out, [
            $periodLabel,
            (string)($r['dept'] ?: $dept),
            (string)$r['project'],
            (string)$r['user'],
            (string)$r['task'],
            (string)$r['date'],
            number_format((float)$r['hours'], 2, '.', ''),
            number_format((float)$r['rate'], 2, '.', ''),
            number_format((float)$r['amount'], 2, '.', ''),
        ], ';');
    }
    fclose($out);
}

function wsTableExists(string $table): bool {
    global $DB;
    $safe = $DB->ForSql($table);
    $res = $DB->Query("SHOW TABLES LIKE '{$safe}'");
    return (bool)$res->Fetch();
}

function wsColumnExists(string $table, string $column): bool {
    global $DB;
    $tableSafe = $DB->ForSql($table);
    $colSafe = $DB->ForSql($column);
    $res = $DB->Query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$colSafe}'");
    return (bool)$res->Fetch();
}

function wsCollectTasksSchema(): array {
    return [
        'b_tasks' => wsGetTableColumns('b_tasks'),
        'b_tasks_elapsed_time' => wsGetTableColumns('b_tasks_elapsed_time'),
        'b_tasks_workgroup' => wsGetTableColumns('b_tasks_workgroup'),
        'b_sonet_group' => wsGetTableColumns('b_sonet_group'),
    ];
}

function wsGetTableColumns(string $table): array {
    global $DB;
    if (!wsTableExists($table)) {
        return ['exists' => false, 'columns' => []];
    }
    $safe = $DB->ForSql($table);
    $res = $DB->Query("SHOW COLUMNS FROM `{$safe}`");
    $cols = [];
    while ($row = $res->Fetch()) {
        $cols[] = [
            'name' => (string)($row['Field'] ?? ''),
            'type' => (string)($row['Type'] ?? ''),
            'null' => (string)($row['Null'] ?? ''),
            'key' => (string)($row['Key'] ?? ''),
        ];
    }
    return ['exists' => true, 'columns' => $cols];
}

function wsDetectTaskFieldMap(): array {
    return [
        'status' => wsColumnExists('b_tasks', 'STATUS'),
        'created_date' => wsColumnExists('b_tasks', 'CREATED_DATE'),
        'closed_date' => wsColumnExists('b_tasks', 'CLOSED_DATE'),
        'deadline' => wsColumnExists('b_tasks', 'DEADLINE'),
        'plan_start' => wsColumnExists('b_tasks', 'START_DATE_PLAN'),
        'plan_end' => wsColumnExists('b_tasks', 'END_DATE_PLAN'),
        'time_estimate' => wsColumnExists('b_tasks', 'TIME_ESTIMATE'),
    ];
}

function wsTaskStatusLabel(?int $status): string {
    $map = [
        1 => 'Новая',
        2 => 'Ждёт выполнения',
        3 => 'Выполняется',
        4 => 'Ждёт контроля',
        5 => 'Завершена',
        6 => 'Отложена',
        7 => 'Отклонена',
    ];
    if ($status === null) return 'Неизвестно';
    return (string)($map[$status] ?? ('Статус #' . $status));
}

function wsReadTaskDateFilters(array $src): array {
    return [
        'created' => ['from' => wsNormDateParam($src['created_from'] ?? ''), 'to' => wsNormDateParam($src['created_to'] ?? '')],
        'plan_start' => ['from' => wsNormDateParam($src['plan_start_from'] ?? ''), 'to' => wsNormDateParam($src['plan_start_to'] ?? '')],
        'plan_end' => ['from' => wsNormDateParam($src['plan_end_from'] ?? ''), 'to' => wsNormDateParam($src['plan_end_to'] ?? '')],
        'deadline' => ['from' => wsNormDateParam($src['deadline_from'] ?? ''), 'to' => wsNormDateParam($src['deadline_to'] ?? '')],
        'closed' => ['from' => wsNormDateParam($src['closed_from'] ?? ''), 'to' => wsNormDateParam($src['closed_to'] ?? '')],
    ];
}

function wsNormDateParam($v): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function wsBuildTaskDateWhereSql(string $alias, array $filters): string {
    global $DB;
    $map = [
        'created' => 'CREATED_DATE',
        'plan_start' => 'START_DATE_PLAN',
        'plan_end' => 'END_DATE_PLAN',
        'deadline' => 'DEADLINE',
        'closed' => 'CLOSED_DATE',
    ];
    $parts = [];
    foreach ($map as $key => $col) {
        if (!wsColumnExists('b_tasks', $col)) continue;
        $from = $filters[$key]['from'] ?? null;
        $to = $filters[$key]['to'] ?? null;
        if ($from) {
            $parts[] = "{$alias}.{$col} >= '" . $DB->ForSql($from . ' 00:00:00') . "'";
        }
        if ($to) {
            $parts[] = "{$alias}.{$col} <= '" . $DB->ForSql($to . ' 23:59:59') . "'";
        }
    }
    if (empty($parts)) return '';
    return ' AND ' . implode(' AND ', $parts);
}

function wsNormalizeDate(string $val): ?string {
    $v = trim($val);
    if ($v === '') return null;
    if (strpos($v, ' ') !== false) return substr($v, 0, 10);
    if (strpos($v, 'T') !== false) return substr($v, 0, 10);
    return strlen($v) >= 10 ? substr($v, 0, 10) : $v;
}

function wsCollectTasksDataDebug(array $deptScope, string $dateFrom, string $dateTo, array $dateFilters = []): array {
    global $DB;
    $deptScope = array_values(array_map('intval', $deptScope));
    if (empty($deptScope)) return ['tasks_sample' => [], 'elapsed_sample' => []];

    $users = [];
    $uRes = \CUser::GetList('ID', 'ASC', ['UF_DEPARTMENT' => $deptScope, 'ACTIVE' => 'Y'], ['FIELDS' => ['ID']]);
    while ($u = $uRes->Fetch()) $users[] = (int)$u['ID'];
    if (empty($users)) return ['tasks_sample' => [], 'elapsed_sample' => []];
    $uids = implode(',', $users);

    $safeFrom = $DB->ForSql($dateFrom . ' 00:00:00');
    $safeTo = $DB->ForSql($dateTo . ' 23:59:59');

    $taskDateWhereSql = wsBuildTaskDateWhereSql('t', $dateFilters);
    $tasks = [];
    $rt = $DB->Query("
        SELECT t.ID, t.TITLE, t.STATUS,
               " . (wsColumnExists('b_tasks', 'CREATED_DATE') ? "t.CREATED_DATE" : "NULL") . " AS CREATED_DATE,
               " . (wsColumnExists('b_tasks', 'CLOSED_DATE') ? "t.CLOSED_DATE" : "NULL") . " AS CLOSED_DATE,
               " . (wsColumnExists('b_tasks', 'START_DATE_PLAN') ? "t.START_DATE_PLAN" : "NULL") . " AS START_DATE_PLAN,
               " . (wsColumnExists('b_tasks', 'END_DATE_PLAN') ? "t.END_DATE_PLAN" : "NULL") . " AS END_DATE_PLAN,
               " . (wsColumnExists('b_tasks', 'DEADLINE') ? "t.DEADLINE" : "NULL") . " AS DEADLINE,
               " . (wsColumnExists('b_tasks', 'TIME_ESTIMATE') ? "t.TIME_ESTIMATE" : "NULL") . " AS TIME_ESTIMATE
        FROM b_tasks t
        WHERE " . (wsColumnExists('b_tasks', 'RESPONSIBLE_ID') ? "t.RESPONSIBLE_ID" : "t.CREATED_BY") . " IN ({$uids})
          {$taskDateWhereSql}
        ORDER BY t.ID DESC
        LIMIT 30
    ");
    while ($row = $rt->Fetch()) $tasks[] = $row;

    $elapsed = [];
    $re = $DB->Query("
        SELECT USER_ID, TASK_ID, MINUTES, CREATED_DATE
        FROM b_tasks_elapsed_time
        WHERE USER_ID IN ({$uids})
          AND CREATED_DATE >= '{$safeFrom}'
          AND CREATED_DATE <= '{$safeTo}'
        ORDER BY CREATED_DATE DESC
        LIMIT 50
    ");
    while ($row = $re->Fetch()) $elapsed[] = $row;

    return ['tasks_sample' => $tasks, 'elapsed_sample' => $elapsed];
}

function wsBuildReportSlices(array $userRows, array $currentWorkRows): array {
    $currentWork = [];
    $periodDone = [];
    $periodInProgress = [];
    $statusSummary = [
        'overall' => ['new' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0, 'other' => 0],
        'by_user' => [],
    ];

    $currentWorkMap = [];
    foreach ($currentWorkRows as $cw) {
        $currentWorkMap[(int)$cw['user_id']] = $cw;
    }

    foreach ($userRows as $user) {
        $grouped = wsGroupTasksByTaskId((array)($user['tasks'] ?? []));
        $userCurrent = (array)($currentWorkMap[(int)$user['user_id']]['tasks'] ?? []);
        $userDone = [];
        $userInProgress = [];
        $userSummary = ['new' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0, 'other' => 0];

        foreach ($grouped as $task) {
            $bucket = wsStatusBucket((int)($task['status_code'] ?? 0));
            $userSummary[$bucket]++;
            $statusSummary['overall'][$bucket]++;

            if ((int)$task['status_code'] === 5) {
                $userDone[] = $task;
            }
            if (in_array((int)$task['status_code'], [2, 3, 4], true)) {
                $userInProgress[] = $task;
            }
        }

        $userStub = [
            'user_id' => $user['user_id'],
            'user_name' => $user['user_name'],
            'dept_name' => $user['dept_name'],
            'total_hours' => $user['total_hours'],
            'projects' => $user['projects'],
        ];
        $currentWork[] = array_merge($userStub, [
            'tasks' => $userCurrent,
            'projects' => (array)($currentWorkMap[(int)$user['user_id']]['projects'] ?? []),
        ]);
        $periodDone[] = array_merge($userStub, ['tasks' => $userDone]);
        $periodInProgress[] = array_merge($userStub, ['tasks' => $userInProgress]);
        $statusSummary['by_user'][] = [
            'user_id' => $user['user_id'],
            'user_name' => $user['user_name'],
            'counts' => $userSummary,
        ];
    }

    return [
        'current_work' => $currentWork,
        'period_done' => $periodDone,
        'period_in_progress' => $periodInProgress,
        'status_summary' => $statusSummary,
    ];
}

function wsBuildCurrentWorkRows(array $users, string $dateFrom, string $dateTo, array $taskFieldMap, array $dateFilters = []): array {
    global $DB;
    if (empty($users)) return [];

    $hasTasksWorkgroup = wsTableExists('b_tasks_workgroup');
    $hasTaskGroupColumn = wsColumnExists('b_tasks', 'GROUP_ID');
    $projectJoinSql = '';
    $projectIdExpr = '0';
    if ($hasTasksWorkgroup) {
        $projectJoinSql = "LEFT JOIN b_tasks_workgroup tg ON tg.TASK_ID = t.ID";
        $projectIdExpr = 'tg.GROUP_ID';
    } elseif ($hasTaskGroupColumn) {
        $projectIdExpr = 't.GROUP_ID';
    }

    $taskUserField = wsColumnExists('b_tasks', 'RESPONSIBLE_ID') ? 'RESPONSIBLE_ID' : 'CREATED_BY';
    $userIds = array_map('intval', array_keys($users));
    $userIdsSql = implode(',', $userIds);
    $safeFrom = $DB->ForSql($dateFrom . ' 00:00:00');
    $safeTo = $DB->ForSql($dateTo . ' 23:59:59');

    $statusExpr = !empty($taskFieldMap['status']) ? 't.STATUS' : 'NULL';
    $createdExpr = !empty($taskFieldMap['created_date']) ? 't.CREATED_DATE' : 'NULL';
    $closedExpr = !empty($taskFieldMap['closed_date']) ? 't.CLOSED_DATE' : 'NULL';
    $deadlineExpr = !empty($taskFieldMap['deadline']) ? 't.DEADLINE' : 'NULL';
    $planStartExpr = !empty($taskFieldMap['plan_start']) ? 't.START_DATE_PLAN' : 'NULL';
    $planEndExpr = !empty($taskFieldMap['plan_end']) ? 't.END_DATE_PLAN' : 'NULL';
    $timeEstimateExpr = !empty($taskFieldMap['time_estimate']) ? 't.TIME_ESTIMATE' : 'NULL';
    $taskDateWhereSql = wsBuildTaskDateWhereSql('t', $dateFilters);

    $sql = "
        SELECT
            t.{$taskUserField}                         AS user_id,
            t.ID                                       AS task_id,
            t.TITLE                                    AS task_name,
            {$projectIdExpr}                           AS project_id,
            sg.NAME                                    AS project_name,
            {$statusExpr}                              AS task_status,
            {$createdExpr}                             AS task_created_date,
            {$closedExpr}                              AS task_closed_date,
            {$deadlineExpr}                            AS task_deadline,
            {$planStartExpr}                           AS task_plan_start,
            {$planEndExpr}                             AS task_plan_end,
            {$timeEstimateExpr}                        AS task_time_estimate,
            ROUND(COALESCE(el.minutes_sum, 0) / 60, 2) AS fact_hours
        FROM b_tasks t
        {$projectJoinSql}
        LEFT JOIN b_sonet_group sg ON sg.ID = {$projectIdExpr}
        LEFT JOIN (
            SELECT USER_ID, TASK_ID, SUM(MINUTES) AS minutes_sum
            FROM b_tasks_elapsed_time
            WHERE CREATED_DATE >= '{$safeFrom}'
              AND CREATED_DATE <= '{$safeTo}'
            GROUP BY USER_ID, TASK_ID
        ) el ON el.USER_ID = t.{$taskUserField} AND el.TASK_ID = t.ID
        WHERE t.{$taskUserField} IN ({$userIdsSql})
          AND t.STATUS IN (1,2,3,4)
          {$taskDateWhereSql}
        ORDER BY t.{$taskUserField} ASC, sg.NAME ASC, t.TITLE ASC
    ";
    WsDiag::add('reports_current_work_sql', ['sql' => $sql, 'task_user_field' => $taskUserField], 2);

    $rows = [];
    foreach ($users as $u) {
        $rows[(int)$u['user_id']] = [
            'user_id' => $u['user_id'],
            'user_name' => $u['user_name'],
            'dept_name' => $u['dept_name'],
            'total_hours' => round((float)$u['total_hours'], 2),
            'projects' => [],
            'tasks' => [],
        ];
    }

    $rs = $DB->Query($sql);
    while ($r = $rs->Fetch()) {
        $uid = (int)$r['user_id'];
        if (!isset($rows[$uid])) continue;
        $projectId = (int)($r['project_id'] ?? 0);
        $projectKey = $projectId > 0 ? 'P' . $projectId : 'NO_PROJECT';

        $planHoursRaw = isset($r['task_time_estimate']) && $r['task_time_estimate'] !== null
            ? round(((float)$r['task_time_estimate']) / 3600, 2)
            : null;
        $planHours = ($planHoursRaw !== null && $planHoursRaw > 0) ? $planHoursRaw : null;
        $factHours = round((float)($r['fact_hours'] ?? 0), 2);
        $statusCode = isset($r['task_status']) ? (int)$r['task_status'] : null;
        $deadline = wsNormalizeDate((string)($r['task_deadline'] ?? ''));
        $createdDate = wsNormalizeDate((string)($r['task_created_date'] ?? ''));
        $closedDate = wsNormalizeDate((string)($r['task_closed_date'] ?? ''));
        $task = [
            'task_id' => (int)$r['task_id'],
            'task_name' => (string)$r['task_name'],
            'project_id' => $projectId > 0 ? $projectId : null,
            'project_name' => $projectId > 0 ? (string)($r['project_name'] ?: ('Проект #' . $projectId)) : 'Без проекта',
            'hours' => $factHours,
            'fact_hours' => $factHours,
            'status_code' => $statusCode,
            'status_label' => wsTaskStatusLabel($statusCode),
            'created_date' => $createdDate,
            'closed_date' => $closedDate,
            'deadline' => $deadline,
            'plan_start' => wsNormalizeDate((string)($r['task_plan_start'] ?? '')),
            'plan_end' => wsNormalizeDate((string)($r['task_plan_end'] ?? '')),
            'plan_hours' => $planHours,
            'variance_hours' => $planHours !== null ? round($factHours - $planHours, 2) : null,
            'is_overdue' => $deadline ? ((strtotime($deadline . ' 23:59:59') < time()) && !in_array($statusCode, [5], true)) : false,
            'date' => null,
        ];

        $rows[$uid]['tasks'][] = $task;
        if (!isset($rows[$uid]['projects'][$projectKey])) {
            $rows[$uid]['projects'][$projectKey] = [
                'project_id' => $task['project_id'],
                'project_name' => $task['project_name'],
                'hours' => 0.0,
                'tasks' => [],
            ];
        }
        $rows[$uid]['projects'][$projectKey]['hours'] += $factHours;
        $rows[$uid]['projects'][$projectKey]['tasks'][] = $task;
    }

    $out = [];
    foreach ($rows as $u) {
        $u['projects'] = array_values(array_map(static function(array $p): array {
            $p['hours'] = round((float)$p['hours'], 2);
            return $p;
        }, $u['projects']));
        $out[] = $u;
    }
    return $out;
}

function wsGroupTasksByTaskId(array $tasks): array {
    $map = [];
    foreach ($tasks as $t) {
        $id = (int)($t['task_id'] ?? 0);
        if ($id <= 0) continue;
        if (!isset($map[$id])) {
            $map[$id] = $t;
            $map[$id]['fact_hours'] = 0.0;
            $map[$id]['hours'] = 0.0;
            $map[$id]['logs'] = [];
        }
        $h = (float)($t['hours'] ?? 0);
        $map[$id]['fact_hours'] += $h;
        $map[$id]['hours'] += $h;
        $map[$id]['logs'][] = ['date' => (string)($t['date'] ?? ''), 'hours' => round($h, 2)];
    }

    $out = [];
    foreach ($map as $task) {
        $planHours = $task['plan_hours'];
        $task['fact_hours'] = round((float)$task['fact_hours'], 2);
        $task['hours'] = $task['fact_hours'];
        $task['variance_hours'] = $planHours !== null ? round($task['fact_hours'] - (float)$planHours, 2) : null;
        $out[] = $task;
    }
    return $out;
}

function wsStatusBucket(int $statusCode): string {
    if ($statusCode === 1) return 'new';
    if (in_array($statusCode, [2, 3], true)) return 'in_progress';
    if ($statusCode === 4) return 'review';
    if ($statusCode === 5) return 'done';
    return 'other';
}

