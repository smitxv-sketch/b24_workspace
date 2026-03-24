<?php
/**
 * Профиль воркспейса по умолчанию.
 * Используется если UF_WORKSPACE_ROLE не заполнено.
 * Файл: /local/bproc/workspace_roles/_default.php
 */
return [
    'role_key' => '_default',
    'label'    => 'Сотрудник',

    'processes' => [
        'deal_tkp' => ['label' => 'Заявки ТКП', 'icon' => 'grid'],
    ],

    'analytics_processes' => [],
];
