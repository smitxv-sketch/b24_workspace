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
    'amount_field' => 'UF_CONTRACT_AMOUNT',

    // Статусы для воронки аналитики
    // stage_id берётся из crmStagesSpecial конфига БП
    'funnel_statuses' => [
        'won'                => ['label' => 'Взято в работу (WON)',   'color' => 'green',     'stage_id' => null],
        'active'             => ['label' => 'Активных сейчас',        'color' => 'blue',      'stage_id' => null],
        'customer_feedback'  => ['label' => 'Ожидание заказчика',     'color' => 'amber',     'stage_id' => 'C1:UC_MK98V5'],
        'customer_postponed' => ['label' => 'Отложено заказчиком',    'color' => 'amber_dim', 'stage_id' => 'C1:UC_2W1UYL'],
        'customer_rejected'  => ['label' => 'Отказ заказчика',        'color' => 'red',       'stage_id' => 'C1:LOSE'],
        'tech_decision_fail' => ['label' => 'Откл. тех. директором',  'color' => 'red_dim',   'stage_id' => 'C1:6'],
    ],
];
