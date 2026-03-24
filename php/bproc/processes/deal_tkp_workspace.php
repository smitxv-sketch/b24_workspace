<?php
/**
 * Конфиг воркспейса для процесса «Заявка на ТКП».
 * Файл: /local/bproc/processes/deal_tkp_workspace.php
 */
return [
    'process_key' => 'deal_tkp',
    'title'       => 'Заявки ТКП',
    'icon'        => 'grid',

    'workspace_roles' => [
        'responsible' => [
            'label'         => 'Владелец процесса',
            'sections'      => ['alert', 'participants', 'timeline', 'tasks', 'discipline', 'feed'],
            'folders'       => ['commercial', 'finance'],
            'filters'       => ['overdue', 'action', 'wait'],
            'can_analytics' => true,
        ],
        'gip' => [
            'label'         => 'ГИП',
            'sections'      => ['alert', 'timeline', 'tasks', 'feed'],
            'folders'       => ['tech_docs'],
            'filters'       => ['action'],
            'can_analytics' => false,
        ],
        'tech_director' => [
            'label'         => 'Технический директор',
            'sections'      => ['alert', 'participants', 'timeline'],
            'folders'       => [],
            'filters'       => ['action', 'overdue'],
            'can_analytics' => true,
        ],
        '_default' => [
            'label'         => 'Участник',
            'sections'      => ['alert', 'timeline'],
            'folders'       => [],
            'filters'       => [],
            'can_analytics' => false,
        ],
    ],

    // Поле суммы сделки
    'amount_field' => 'UF_CRM_1761043434',

    // Статусы для воронки аналитики (только UI-метаданные).
    // stage_id берётся из crmStagesSpecial в основном process-конфиге (SSOT).
    'funnel_statuses' => [
        'won'                => ['label' => 'Взято в работу (WON)',  'color' => 'green'],
        'active'             => ['label' => 'Активных сейчас',       'color' => 'blue'],
        'customer_feedback'  => ['label' => 'Ожидание заказчика',    'color' => 'amber'],
        'customer_postponed' => ['label' => 'Отложено заказчиком',   'color' => 'amber_dim'],
        'customer_rejected'  => ['label' => 'Отказ заказчика',       'color' => 'red'],
        'tech_decision_fail' => ['label' => 'Откл. тех. директором', 'color' => 'red_dim'],
    ],
];
