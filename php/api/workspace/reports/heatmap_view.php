<?php
/**
 * Представление heatmap (фактическая загрузка по elapsed time).
 */

function wsBuildHeatmapView(array $options): array {
    global $DB;

    $deptScope = array_map('intval', (array)($options['dept_scope'] ?? []));
    $scale = (string)($options['planning_scale'] ?? 'day');
    $selectedDeptId = (int)($options['selected_dept_id'] ?? 0);
    if (empty($deptScope)) {
        return wsHeatmapEmpty($scale);
    }

    $timelineCfg = wsPlanningBuildTimeline($scale);
    $rangeFrom = (string)$timelineCfg['range']['from'];
    $rangeTo = (string)$timelineCfg['range']['to'];
    $safeFrom = $DB->ForSql($rangeFrom . ' 00:00:00');
    $safeTo = $DB->ForSql($rangeTo . ' 23:59:59');

    $users = wsReportsLoadUsersByDepartments($deptScope);
    if (empty($users)) {
        return wsHeatmapEmpty($scale);
    }
    $userIds = array_map('intval', array_keys($users));
    $userIdsSql = implode(',', $userIds);

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

    // Логи трудозатрат в рамках окна.
    $sql = "
        SELECT
            te.USER_ID AS user_id,
            te.TASK_ID AS task_id,
            DATE(te.CREATED_DATE) AS work_date,
            ROUND(SUM(te.MINUTES) / 60, 2) AS hours,
            t.TITLE AS task_name,
            {$projectIdExpr} AS project_id,
            sg.NAME AS project_name,
            t.START_DATE_PLAN AS plan_start,
            t.END_DATE_PLAN AS plan_end,
            t.DEADLINE AS deadline,
            t.STATUS AS task_status
        FROM b_tasks_elapsed_time te
        INNER JOIN b_tasks t ON t.ID = te.TASK_ID
        {$projectJoinSql}
        LEFT JOIN b_sonet_group sg ON sg.ID = {$projectIdExpr}
        WHERE te.CREATED_DATE >= '{$safeFrom}'
          AND te.CREATED_DATE <= '{$safeTo}'
          AND te.USER_ID IN ({$userIdsSql})
        GROUP BY te.USER_ID, te.TASK_ID, DATE(te.CREATED_DATE), project_id
        ORDER BY te.USER_ID ASC, work_date ASC
    ";
    WsDiag::add('reports_heatmap_sql', ['sql' => $sql], 2);
    $rs = $DB->Query($sql);

    $entriesByUser = [];
    while ($r = $rs->Fetch()) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0 || !isset($users[$uid])) continue;
        $entriesByUser[$uid][] = [
            'task_id' => (int)$r['task_id'],
            'task_name' => (string)$r['task_name'],
            'work_date' => (string)$r['work_date'],
            'hours' => (float)$r['hours'],
            'project_name' => (string)($r['project_name'] ?? ''),
            'user_name' => (string)$users[$uid]['user_name'],
            'plan_start' => wsNormalizeDate((string)($r['plan_start'] ?? '')),
            'plan_end' => wsNormalizeDate((string)($r['plan_end'] ?? '')),
            'deadline' => wsNormalizeDate((string)($r['deadline'] ?? '')),
            'status_label' => wsTaskStatusLabel(isset($r['task_status']) ? (int)$r['task_status'] : null),
        ];
    }

    // Кол-во активных проектов сейчас (грубая управленческая метрика).
    $activeProjectsByUser = wsHeatmapLoadActiveProjectsByUser($userIds);

    $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
    $departments = wsPlanningBuildDeptNodes($selectedDeptId);
    $deptMap = [];
    foreach ($departments as $d) {
        $deptMap[(int)$d['id']] = ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'users' => []];
    }
    if (!isset($deptMap[$selectedDeptId])) {
        $deptMap[$selectedDeptId] = ['id' => $selectedDeptId, 'name' => wsGetDeptNameById($selectedDeptId), 'users' => []];
    }

    $summary = ['overloaded' => [], 'normal' => [], 'free' => []];
    foreach ($users as $uid => $u) {
        $cells = wsHeatmapBuildCells((array)($entriesByUser[$uid] ?? []), (array)$timelineCfg['timeline']);
        $current = wsHeatmapCurrentLoad($cells, (array)$timelineCfg['timeline'], $today);
        $loadPct = (int)round(($current / 8.0) * 100);

        $row = [
            'user_id' => (int)$u['user_id'],
            'user_name' => (string)$u['user_name'],
            'dept_id' => (int)$u['dept_id'],
            'dept_name' => (string)$u['dept_name'],
            'load_now_hours' => round($current, 2),
            'load_now_pct' => $loadPct,
            'active_projects' => (int)($activeProjectsByUser[(int)$uid] ?? 0),
            'cells' => $cells,
        ];

        if ($loadPct > 100) {
            $summary['overloaded'][] = $row;
        } elseif ($loadPct >= 60) {
            $summary['normal'][] = $row;
        } else {
            $summary['free'][] = $row;
        }

        $userDeptId = (int)$u['dept_id'];
        $targetDeptId = isset($deptMap[$userDeptId]) ? $userDeptId : $selectedDeptId;
        $deptMap[$targetDeptId]['users'][] = $row;
    }

    foreach (['overloaded', 'normal', 'free'] as $key) {
        usort($summary[$key], static fn(array $a, array $b): int => ($b['load_now_pct'] <=> $a['load_now_pct']));
    }

    $departmentsOut = array_values($deptMap);
    usort($departmentsOut, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
    foreach ($departmentsOut as &$d) {
        usort($d['users'], static fn(array $a, array $b): int => strcmp((string)$a['user_name'], (string)$b['user_name']));
    }
    unset($d);

    return [
        'scale' => $scale,
        'timeline' => $timelineCfg['timeline'],
        'today_index' => $timelineCfg['today_index'],
        'anchor_quarter_index' => $timelineCfg['anchor_quarter_index'],
        'range' => $timelineCfg['range'],
        'departments' => $departmentsOut,
        'summary' => $summary,
    ];
}

function wsHeatmapLoadActiveProjectsByUser(array $userIds): array {
    global $DB;
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $v): bool => $v > 0)));
    if (empty($userIds)) return [];
    $userIdsSql = implode(',', $userIds);

    $projectExpr = wsColumnExists('b_tasks', 'GROUP_ID') ? 't.GROUP_ID' : '0';
    $sql = "
        SELECT t.RESPONSIBLE_ID AS user_id, COUNT(DISTINCT {$projectExpr}) AS cnt
        FROM b_tasks t
        WHERE t.RESPONSIBLE_ID IN ({$userIdsSql})
          AND t.STATUS IN (1,2,3,4)
          AND {$projectExpr} IS NOT NULL
          AND {$projectExpr} > 0
        GROUP BY t.RESPONSIBLE_ID
    ";
    $rs = $DB->Query($sql);
    $map = [];
    while ($r = $rs->Fetch()) {
        $map[(int)$r['user_id']] = (int)$r['cnt'];
    }
    return $map;
}

function wsHeatmapBuildCells(array $entries, array $timeline): array {
    $cells = [];
    foreach ($timeline as $bucket) {
        $from = (string)($bucket['from'] ?? '');
        $to = (string)($bucket['to'] ?? '');
        $tasks = [];
        $hours = 0.0;
        foreach ($entries as $e) {
            $d = (string)($e['work_date'] ?? '');
            if ($d < $from || $d > $to) continue;
            $hours += (float)$e['hours'];
            $tasks[] = [
                'task_id' => (int)$e['task_id'],
                'task_name' => (string)$e['task_name'],
                'project_name' => (string)($e['project_name'] ?? ''),
                'user_name' => (string)($e['user_name'] ?? ''),
                'plan_start' => (string)$e['plan_start'],
                'plan_end' => (string)$e['plan_end'],
                'hours' => round((float)$e['hours'], 2),
                'status_label' => (string)$e['status_label'],
            ];
        }
        $level = wsHeatmapLevel($hours);
        $cells[] = [
            'index' => (int)$bucket['index'],
            'hours' => round($hours, 2),
            'intensity' => wsHeatmapIntensity($hours),
            'level' => $level,
            'tasks_preview' => $tasks,
        ];
    }
    return $cells;
}

function wsHeatmapCurrentLoad(array $cells, array $timeline, string $today): float {
    foreach ($timeline as $b) {
        $from = (string)($b['from'] ?? '');
        $to = (string)($b['to'] ?? '');
        if ($from <= $today && $today <= $to) {
            foreach ($cells as $c) {
                if ((int)$c['index'] === (int)$b['index']) return (float)($c['hours'] ?? 0);
            }
        }
    }
    return 0.0;
}

function wsHeatmapLevel(float $hours): string {
    if ($hours > 10) return 'overload';
    if ($hours >= 8) return 'high';
    if ($hours >= 4) return 'normal';
    return 'low';
}

function wsHeatmapIntensity(float $hours): float {
    if ($hours <= 0) return 0.0;
    return min(1.0, $hours / 12.0);
}

function wsHeatmapEmpty(string $scale): array {
    $cfg = wsPlanningBuildTimeline($scale);
    return [
        'scale' => $cfg['scale'],
        'timeline' => $cfg['timeline'],
        'today_index' => $cfg['today_index'],
        'anchor_quarter_index' => $cfg['anchor_quarter_index'],
        'range' => $cfg['range'],
        'departments' => [],
        'summary' => ['overloaded' => [], 'normal' => [], 'free' => []],
    ];
}

