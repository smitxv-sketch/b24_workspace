<?php
/**
 * Построение представления "Планирование Задачи/день".
 */

function wsBuildPlanningView(array $options): array {
    global $DB;

    $selectedDeptId = (int)($options['selected_dept_id'] ?? 0);
    $deptScope = array_map('intval', (array)($options['dept_scope'] ?? []));
    $planningScale = (string)($options['planning_scale'] ?? 'day');
    $dateFilters = (array)($options['date_filters'] ?? []);
    $taskFieldMap = (array)($options['task_field_map'] ?? []);

    $timelineCfg = wsPlanningBuildTimeline($planningScale);
    $rangeFrom = (string)$timelineCfg['range']['from'];
    $rangeTo = (string)$timelineCfg['range']['to'];

    $users = wsReportsLoadUsersByDepartments($deptScope);
    if (empty($users)) {
        return [
            'scale' => $planningScale,
            'timeline' => $timelineCfg['timeline'],
            'today_index' => $timelineCfg['today_index'],
            'anchor_quarter_index' => $timelineCfg['anchor_quarter_index'],
            'range' => $timelineCfg['range'],
            'departments' => [],
        ];
    }

    $userIds = array_map('intval', array_keys($users));
    $userIdsSql = implode(',', $userIds);
    $safeFrom = $DB->ForSql($rangeFrom . ' 00:00:00');
    $safeTo = $DB->ForSql($rangeTo . ' 23:59:59');

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

    $deadlineExpr = !empty($taskFieldMap['deadline']) ? 't.DEADLINE' : 'NULL';
    $createdExpr = !empty($taskFieldMap['created_date']) ? 't.CREATED_DATE' : 'NULL';
    $closedExpr = !empty($taskFieldMap['closed_date']) ? 't.CLOSED_DATE' : 'NULL';
    $taskDateWhereSql = wsBuildTaskDateWhereSql('t', $dateFilters);

    $sql = "
        SELECT
            t.ID AS task_id,
            t.TITLE AS task_name,
            t.RESPONSIBLE_ID AS user_id,
            t.STATUS AS task_status,
            {$projectIdExpr} AS project_id,
            sg.NAME AS project_name,
            t.START_DATE_PLAN AS plan_start,
            t.END_DATE_PLAN AS plan_end,
            {$deadlineExpr} AS deadline,
            {$createdExpr} AS created_date,
            {$closedExpr} AS closed_date
        FROM b_tasks t
        {$projectJoinSql}
        LEFT JOIN b_sonet_group sg ON sg.ID = {$projectIdExpr}
        WHERE t.RESPONSIBLE_ID IN ({$userIdsSql})
          AND t.START_DATE_PLAN IS NOT NULL
          AND t.END_DATE_PLAN IS NOT NULL
          AND t.END_DATE_PLAN >= '{$safeFrom}'
          AND t.START_DATE_PLAN <= '{$safeTo}'
          {$taskDateWhereSql}
        ORDER BY t.RESPONSIBLE_ID ASC, t.START_DATE_PLAN ASC, t.TITLE ASC
    ";
    WsDiag::add('reports_planning_sql', ['sql' => $sql, 'scale' => $planningScale], 2);

    $tasksByUser = [];
    $rs = $DB->Query($sql);
    while ($r = $rs->Fetch()) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0 || !isset($users[$uid])) continue;
        $planStart = wsNormalizeDate((string)($r['plan_start'] ?? ''));
        $planEnd = wsNormalizeDate((string)($r['plan_end'] ?? ''));
        if ($planStart === '' || $planEnd === '') continue;

        $tasksByUser[$uid][] = [
            'task_id' => (int)$r['task_id'],
            'task_name' => (string)$r['task_name'],
            'status_code' => isset($r['task_status']) ? (int)$r['task_status'] : null,
            'status_label' => wsTaskStatusLabel(isset($r['task_status']) ? (int)$r['task_status'] : null),
            'project_id' => (int)($r['project_id'] ?? 0) ?: null,
            'project_name' => (string)($r['project_name'] ?? ''),
            'plan_start' => $planStart,
            'plan_end' => $planEnd,
            'deadline' => wsNormalizeDate((string)($r['deadline'] ?? '')),
            'created_date' => wsNormalizeDate((string)($r['created_date'] ?? '')),
            'closed_date' => wsNormalizeDate((string)($r['closed_date'] ?? '')),
        ];
    }

    // Только один уровень подчинения: selected dept + прямые дочерние.
    $deptNodes = wsPlanningBuildDeptNodes($selectedDeptId);
    $byDept = [];
    foreach ($deptNodes as $d) {
        $byDept[(int)$d['id']] = [
            'id' => (int)$d['id'],
            'name' => (string)$d['name'],
            'users' => [],
        ];
    }
    if (!isset($byDept[$selectedDeptId])) {
        $byDept[$selectedDeptId] = ['id' => $selectedDeptId, 'name' => wsGetDeptNameById($selectedDeptId), 'users' => []];
    }

    foreach ($users as $uid => $u) {
        $userDeptId = (int)$u['dept_id'];
        $targetDeptId = isset($byDept[$userDeptId]) ? $userDeptId : $selectedDeptId;
        $cells = wsPlanningBuildCellsForUser((array)($tasksByUser[(int)$uid] ?? []), (array)$timelineCfg['timeline']);
        $byDept[$targetDeptId]['users'][] = [
            'user_id' => (int)$u['user_id'],
            'user_name' => (string)$u['user_name'],
            'dept_id' => $userDeptId,
            'dept_name' => (string)$u['dept_name'],
            'cells' => $cells,
            'tasks_count' => count((array)($tasksByUser[(int)$uid] ?? [])),
        ];
    }

    $departments = array_values($byDept);
    usort($departments, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
    foreach ($departments as &$d) {
        usort($d['users'], static fn(array $a, array $b): int => strcmp((string)$a['user_name'], (string)$b['user_name']));
    }
    unset($d);

    return [
        'scale' => $planningScale,
        'timeline' => $timelineCfg['timeline'],
        'today_index' => $timelineCfg['today_index'],
        'anchor_quarter_index' => $timelineCfg['anchor_quarter_index'],
        'range' => $timelineCfg['range'],
        'departments' => $departments,
    ];
}

function wsPlanningBuildDeptNodes(int $selectedDeptId): array {
    $selectedDeptId = (int)$selectedDeptId;
    if ($selectedDeptId <= 0) return [];

    $nodes = [[
        'id' => $selectedDeptId,
        'name' => wsGetDeptNameById($selectedDeptId),
    ]];
    if (!class_exists('\CIBlockSection')) return $nodes;

    $res = \CIBlockSection::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['SECTION_ID' => $selectedDeptId, 'GLOBAL_ACTIVE' => 'Y'],
        false,
        ['ID', 'NAME']
    );
    while ($row = $res->Fetch()) {
        $nodes[] = [
            'id' => (int)$row['ID'],
            'name' => (string)($row['NAME'] ?? ('Отдел #' . (int)$row['ID'])),
        ];
    }
    return $nodes;
}

function wsPlanningBuildCellsForUser(array $tasks, array $timeline): array {
    $cells = [];
    foreach ($timeline as $bucket) {
        $bucketFrom = (string)($bucket['from'] ?? '');
        $bucketTo = (string)($bucket['to'] ?? '');
        $bucketTasks = [];
        foreach ($tasks as $t) {
            $start = (string)($t['plan_start'] ?? '');
            $end = (string)($t['plan_end'] ?? '');
            if ($start === '' || $end === '') continue;
            if ($end < $bucketFrom || $start > $bucketTo) continue;
            $bucketTasks[] = [
                'task_id' => (int)$t['task_id'],
                'task_name' => (string)$t['task_name'],
                'plan_start' => $start,
                'plan_end' => $end,
                'status_label' => (string)$t['status_label'],
                'project_name' => (string)$t['project_name'],
            ];
        }

        $load = count($bucketTasks);
        $intensity = 0.0;
        if ($load > 0) {
            // Мягкая шкала 1..5+ задач.
            $intensity = min(1.0, $load / 5.0);
        }
        $cells[] = [
            'index' => (int)$bucket['index'],
            'load' => $load,
            'intensity' => round($intensity, 3),
            'tasks_preview' => $bucketTasks,
        ];
    }
    return $cells;
}

