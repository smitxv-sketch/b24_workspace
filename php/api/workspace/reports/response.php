<?php
/**
 * Сборка финального JSON-ответа для reports endpoint.
 */

function wsBuildReportsResponse(array $ctx): array {
    return [
        'period' => [
            'from' => $ctx['period']['from'],
            'to' => $ctx['period']['to'],
            'label' => $ctx['period']['label'],
            'preset' => $ctx['period']['preset'],
        ],
        'filters' => [
            'group_mode' => $ctx['group_mode'],
            'include_subdepts' => $ctx['include_subdepts'] ? 'Y' : 'N',
            'sort_by' => $ctx['sort_by'],
            'date_filters' => $ctx['date_filters'],
            'view' => $ctx['view'],
            'planning_scale' => $ctx['planning_scale'],
        ],
        'selected_dept_id' => $ctx['selected_dept_id'],
        'expand_dept' => $ctx['expand_dept'] > 0 ? $ctx['expand_dept'] : $ctx['selected_dept_id'],
        'dept_tree' => $ctx['dept_tree'],
        'rows' => $ctx['report_data']['rows'],
        'totals' => $ctx['report_data']['totals'],
        'current_work' => $ctx['report_data']['current_work'],
        'period_done' => $ctx['report_data']['period_done'],
        'period_in_progress' => $ctx['report_data']['period_in_progress'],
        'status_summary' => $ctx['report_data']['status_summary'],
        'task_fields' => $ctx['task_field_map'],
        'schema' => $ctx['schema'],
        'data_debug' => $ctx['data_debug_payload'],
        'planning' => $ctx['planning'],
        'heatmap' => $ctx['heatmap'],
    ];
}

