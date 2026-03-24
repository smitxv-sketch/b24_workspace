<?php
/**
 * Профиль воркспейса: Коммерческий директор.
 * Файл: /local/bproc/workspace_roles/commercial_director.php
 *
 * Привязка к пользователю: UF_WORKSPACE_ROLE = 'commercial_director'
 */
return [
    'role_key' => 'commercial_director',
    'label'    => 'Коммерческий директор',

    'processes' => [
        'deal_tkp'        => ['label' => 'Заявки ТКП',  'icon' => 'grid'],
        'sp1070_contract' => ['label' => 'Договоры',     'icon' => 'doc'],
        'sp1058_project'  => ['label' => 'Проекты',      'icon' => 'clock'],
        'reports'         => ['label' => 'Отчёты',       'icon' => 'chart'],
    ],

    'analytics_processes' => ['deal_tkp'],
];
