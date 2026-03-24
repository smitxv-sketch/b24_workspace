<?php
/**
 * Модуль bootstrap для reports endpoint.
 * Держим чтение входных параметров отдельно от бизнес-логики.
 */

function wsReportsReadRequest(): array {
    return [
        'report' => (string)($_GET['report'] ?? ''),
        'include_subdepts' => strtoupper((string)($_GET['include_subdepts'] ?? 'Y')) !== 'N',
        'group_mode' => (string)($_GET['group_mode'] ?? 'users_projects'),
        'sort_by' => (string)($_GET['sort_by'] ?? 'hours_desc'),
        'period_preset' => (string)($_GET['period_preset'] ?? 'month'),
        'format' => strtolower((string)($_GET['format'] ?? 'json')),
        'schema_debug' => strtoupper((string)($_GET['schema_debug'] ?? 'N')) === 'Y',
        'data_debug' => strtoupper((string)($_GET['data_debug'] ?? 'N')) === 'Y',
        'requested_dept_id' => (int)($_GET['dept_id'] ?? 0),
        'expand_dept' => (int)($_GET['expand_dept'] ?? 0),
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
        // Новые параметры представления планирования.
        'view' => (string)($_GET['view'] ?? 'workload'),
        'planning_scale' => (string)($_GET['planning_scale'] ?? 'day'),
    ];
}

