<?php
/**
 * Источники данных для reports/planning.
 */

function wsReportsLoadUsersByDepartments(array $deptIds): array {
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds), static fn(int $v): bool => $v > 0)));
    if (empty($deptIds)) return [];

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
            'dept_id' => $deptId,
            'dept_name' => wsGetDeptNameById($deptId),
            'hourly_rate' => (float)($u['UF_HOURLY_RATE'] ?? 0),
        ];
    }
    return $users;
}

