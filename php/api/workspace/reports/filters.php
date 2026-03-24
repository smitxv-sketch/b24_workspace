<?php
/**
 * Утилиты фильтрации для planning.
 */

function wsBuildStatusFilterSqlFromScopes(array $taskScopes): string {
    // Пока taskScopes не прокидываются с фронта в API.
    // Оставляем расширяемую точку для будущего, чтобы не менять planning-схему.
    $allowed = [];
    foreach ($taskScopes as $scope) {
        if ($scope === 'planned') $allowed[] = 2;
        if ($scope === 'in_work') { $allowed[] = 3; $allowed[] = 4; }
        if ($scope === 'done') $allowed[] = 5;
    }
    $allowed = array_values(array_unique(array_filter(array_map('intval', $allowed), static fn(int $v): bool => $v > 0)));
    if (empty($allowed)) return '';
    return ' AND t.STATUS IN (' . implode(',', $allowed) . ')';
}

