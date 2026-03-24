# 06 — Архитектура: BpLog, BpContext, BpConfigValidator, Виджет

> Библиотека `/local/bproc/lib/` — переиспользуемые компоненты для всех sys_*.php скриптов.

---

## §6.1 BpLog — централизованное логирование

**Файл:** `/local/bproc/lib/BpLog.php`

### Два независимых канала

| Канал | Где видно | Когда работает |
|-------|-----------|----------------|
| Журнал БП (`WriteToTrackingService`) | Админка Битрикса → Бизнес-процессы → Журнал | Только если БП запустился |
| Файловый лог (`/local/bproc/logs/{script}.txt`) | SSH / файловая система | **Всегда**, даже при фатальном краше |

> Если журнал БП пустой — смотри файловый лог. Он переживает даже `E_ERROR`.

### Инициализация в начале каждого скрипта

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpLog.php';

BpLog::registerFatalHandler('my_script'); // перехват PHP-фаталов, маркер [START]

$root = isset($GLOBALS['__bp_root']) ? $GLOBALS['__bp_root'] : $this->GetRootActivity();

BpLog::init(
    fn(string $msg) => $root->WriteToTrackingService($msg),
    BpLog::LEVEL_DEBUG,  // уровень журнала БП
    BpLog::LEVEL_DEBUG   // уровень файлового лога
);
```

**Уровни по окружению:**

| Режим | Журнал БП | Файловый лог |
|-------|-----------|--------------|
| Отладка | `LEVEL_DEBUG` | `LEVEL_DEBUG` |
| Продакшн | `LEVEL_ERROR` | `LEVEL_INFO` |
| Тишина | `LEVEL_OFF` | `LEVEL_OFF` |

### Методы логирования

```php
BpLog::info('my_script',  'Шаг завершён', ['step' => $stepKey]);
BpLog::debug('my_script', 'Промежуточное значение', ['val' => $val]);
BpLog::error('my_script', 'Что-то пошло не так', ['e' => $e->getMessage()]);

// Аномалия — подозрительное состояние, не обязательно фатальное
BpLog::anomaly('my_script', 'Папка не найдена в маппинге');

// Аномалия с машиночитаемым кодом — удобно искать в логах
BpLog::anomalyCode('my_script', 'ANOMALY_FOLDER_NOT_IN_JSON', 'Папка не в JSON', [
    'folder' => $folderKey,
    'step'   => $stepKey,
]);
```

Формат строки в файле: `[2026-03-13T10:00:00+03:00] [INFO] сообщение | key=value`

### Сброс логов перед прогоном

```php
// В master_init.php — чистим перед каждым запуском БП
BpLog::clearLogs();
```

### «Бортовой самописец» — объединённый лог

```php
// В admin/health.php — все скрипты одной хронологической лентой
echo BpLog::merge();
```

`merge()` читает все `.txt` из `logs/`, парсит ISO8601-timestamp и сортирует строки хронологически. Добавляет `[имя_скрипта]` к каждой строке.

### Регистрация фатального обработчика

`registerFatalHandler('script_name')`:
- Создаёт файл `logs/script_name.txt` с маркером `[START]` — видно что скрипт вообще запустился
- Регистрирует `register_shutdown_function` для перехвата `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`
- Пишет `[FATAL] тип: сообщение in файл:строка`

---

## §6.2 BpContext — контекст документа БП

**Файл:** `/local/bproc/lib/BpContext.php`

Устраняет дублирование блока «определение entityTypeId/docId», который был скопирован в ~19 скриптах.

### Что инкапсулирует

- Разбор `GetDocumentId()` → `entityTypeId` + `docId`
- Поддержку форматов `DEAL_{id}` и `DYNAMIC_{typeId}_{id}`
- Чтение/запись JSON-state через BpStorage (без кэша Factory)
- Поиск конфига процесса через `findProcessConfig()`

### Использование

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpContext.php';

// Автоопределение root (приоритет $GLOBALS['__bp_root'], затем $this)
$ctx = BpContext::detect($this);

// Публичные свойства
$ctx->entityTypeId; // 2 = сделка, 1070+ = смарт-процесс
$ctx->docId;        // ID документа
$ctx->docCode;      // строка "DEAL_4793" или "DYNAMIC_1070_555"
$ctx->root;         // объект root activity
```

### Чтение и запись state

```php
// Читает JSON из UF-поля через BpStorage::readJson (без кэша!)
$state = $ctx->getState();

// Сохраняет через BpStorage::writeJson + обновляет внутренний кэш
$ctx->saveState($state);

// Принудительный сброс кэша (если внешний код изменил state в БД)
$ctx->resetCache();
```

### Получение конфига процесса

```php
$configData = $ctx->getProcessConfig();
// или с фильтром по воронке:
$configData = $ctx->getProcessConfig($categoryId);

if ($configData) {
    $key    = $configData['key'];    // 'deal_standard'
    $config = $configData['config']; // массив конфига
    $steps  = $config['steps'];
}
```

`processKey` ищется в порядке:
1. `state['nav']['processKey']`
2. `state['processKey']` (старый формат)
3. Переменная БП `process_key` (fallback при первом запуске)

### Получение кода поля

```php
// Обёртка над getFieldCode() из config_bp_constants.php
$fieldCode = $ctx->getFieldCode('json');       // основное JSON-поле
$fieldCode = $ctx->getFieldCode('rights');     // поле прав
$fieldCode = $ctx->getFieldCode('status');     // поле виджета
$fieldCode = $ctx->getFieldCode('link_active'); // ссылка на активную папку
```

### Разбор docCode — форматы

| Строка `docCode` | entityTypeId | docId |
|------------------|-------------|-------|
| `DEAL_4793` | 2 | 4793 |
| `DYNAMIC_1070_555` | 1070 | 555 |
| `DYNAMIC_1074_12` | 1074 | 12 |

---

## §6.3 BpConfigValidator — проверка конфига при старте

**Файл:** `/local/bproc/lib/BpConfigValidator.php`

Принцип Fail-Fast: ловит ошибки конфигурации **до запуска**, а не молча падает в середине.

### Использование в master_init.php

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpConfigValidator.php';

$errors = BpConfigValidator::validate($processConfig, $entityTypeId);
if (!empty($errors)) {
    BpLog::error('init', 'Конфиг невалиден', ['errors' => $errors]);
    return; // не запускаем БП
}
```

### Что проверяет

| # | Проверка | Что ловит |
|---|----------|-----------|
| 1 | Все ключи `steps` есть в `crmStages` (кроме exempt) | Опечатку в ключе шага |
| 2 | Все ключи `crmStages` есть в `steps` | Опечатку в crmStages |
| 3 | Шаги `type=circle` имеют запись в `circles` | Незаполненный конфиг круга |
| 4 | Все ключи `circles` ссылаются на реальные шаги | Осиротевший круг |
| 5 | Шаги `returnable=true` не имеют `type=final` | Невозможный возврат |
| 6 | Роли в `circles.approvers` есть в `roles` | Опечатку в имени роли |
| 7 | Все `type` значений из допустимых | Опечатку в type (`circel`) |

### Допустимые типы шагов

```
auto | human | approval | subprocess | circle | wait | final
```

### Исключения для проверки стадий

```php
// Шаги, которые не обязаны менять CRM-стадию:
// В конфиге процесса:
'STAGES_EXEMPT' => ['_complete', 'my_special_step'],
```

### Формат circles (новый и legacy)

```php
// ✅ Новый формат (v2.0)
'circles' => [
    'approval_tkp' => [
        'approvers' => ['manager', 'director'], // роли из $config['roles']
    ],
],

// ⚠️ Legacy (устаревший, валидатор предупредит)
'circles' => [
    'approval_tkp' => [
        'staticApprovers'  => ['manager'],
        'dynamicApprovers' => ['field:UF_CRM_APPROVER'], // пропускается валидатором
    ],
],
```

---

## §6.4 sys_widget_render — HTML-виджет в карточке CRM

**Файл:** `/local/bproc/sys_widget_render.php` (v4.1)

Рендерит HTML-статус процесса и сохраняет его в UF-поле (`UF_..._STATUS`), которое через локальное приложение отображается вкладкой в карточке CRM.

### Архитектура

```
sys_widget_render.php          — точка входа
  ├── BpLog / BpContext        — логирование и контекст
  ├── sys_widget_render_helpers_v7.php  — шлюз хелперов (обратная совместимость)
  │     └── widget/            — модули рендера (с v7.2)
  │           ├── render/ProgressRenderer.php
  │           ├── render/AlertsRenderer.php
  │           ├── render/TimelineRenderer.php
  │           ├── render/StagesRenderer.php
  │           ├── render/ApprovalsRenderer.php
  │           ├── render/RightsRenderer.php
  │           ├── render/TeamRenderer.php
  │           └── render/EfficiencyRenderer.php
  └── tpl_widget_v7.html       — HTML-шаблон с плейсхолдерами {{KEY}}
```

### Что рендерит

| Секция | Источник данных | Хелпер |
|--------|----------------|--------|
| Этапы процесса | `state['stages']` + конфиг | `renderStagesListV7()` |
| Прогресс-бар | `state['stages']` | `renderProgressData()` |
| Алерты | `state['alerts']` | `renderAlertsHTML()` |
| Хронология | `state['history']` | `renderTimelineHTML()` |
| Круги согласования | `state['approvals']` | `wrRenderOneLoop()` |
| Права доступа | `UF_..._RIGHTS` (через BpStorage!) | `wrGenerateRightsHTML()` |
| Команда | конфиг + `$item` | `renderTeamHTML()` |
| Эффективность | `state` + конфиг | `renderEfficiencyHTML()` |

### Ключевой паттерн: права читаются через BpStorage, не ORM

```php
// ✅ Права пишет sys_apply_rights через SQL → читаем тоже через SQL
$rightsRaw = BpStorage::readField($ctx->entityTypeId, $ctx->docId, $FRIGHTS);

// ❌ $item->get($FRIGHTS) — может содержать кэш до записи прав
```

### Сохранение результата

```php
// HTML сохраняется в UF-поле через BpStorage (прямой SQL, без кэша)
BpStorage::writeField($ctx->entityTypeId, $ctx->docId, $FHTMLSTATUS, $finalHtml);
```

### Плейсхолдеры шаблона

```
{{CSS_STYLES}}         — объединённые CSS (основные + согласования)
{{ENTITY_TITLE}}       — заголовок документа
{{CLIENT_NAME}}        — название компании
{{CREATE_DATE}}        — дата создания
{{LINK_FOLDER}}        — ссылка на активную папку Диска
{{VERSION}}            — globalVersion из state
{{STATE_LABEL}}        — текущий этап "Этап: Подготовка"
{{PREP_STAGES_LIST}}   — HTML этапов
{{APPROVAL_BLOCKS}}    — HTML кругов согласования
{{RIGHTS_HTML}}        — HTML прав
{{ALERTS_HTML}}        — HTML алертов
{{PROGRESS_PERCENT}}   — % выполнения
{{TIMELINE_HTML}}      — HTML хронологии
{{TEAM_HTML}}          — HTML команды
{{EFFICIENCY_HTML}}    — HTML эффективности
```

### Порядок этапов — всегда из конфига

```php
// Порядок берётся из config['steps'], а не из state['stages']
// Это гарантирует корректные русские названия без перезапуска БП
foreach ($configSteps as $stepKey => $stepCfg) {
    $orderedStages[$stepKey] = $state['stages'][$stepKey] ?? ['status' => 'wait', ...];
}
```

### Ошибки виджета (из `05_ERRORS.md §5.2` + дополнения)

| Симптом | Причина | Решение |
|---------|---------|---------|
| `WRONG_AUTH_TYPE` | `placement.bind` вызван через вебхук | Только через локальное приложение |
| Вкладка не появляется | Placement не зарегистрирован или кэш | Проверить лог установки, Ctrl+F5 |
| `undefined` entityTypeId в JS | Виджет не получает параметры | `info.options.ENTITY_TYPE_ID \|\| info.options.entityTypeId \|\| 1070` |
| Старые данные в виджете | Кэш браузера | `'HANDLER' => 'https://site.ru/widget.php?v=' . time()` |
| Права не отображаются | `$item->get()` вернул кэш | Использовать `BpStorage::readField()` |

---

## §6.5 Стандартный заголовок sys_*.php скрипта

Шаблон для любого нового скрипта в системе:

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpLog.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpContext.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpStorage.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/config_bp_constants.php';

use Bitrix\Main\Loader;
Loader::includeModule('crm');
Loader::includeModule('disk'); // если нужен

BpLog::registerFatalHandler('my_script_name');

try {
    $root = isset($GLOBALS['__bp_root']) ? $GLOBALS['__bp_root'] : $this->GetRootActivity();

    BpLog::init(
        fn(string $msg) => $root->WriteToTrackingService($msg),
        BpLog::LEVEL_DEBUG,
        BpLog::LEVEL_DEBUG
    );

    $ctx = BpContext::detect($this);

    BpLog::info('my_script_name', "START | doc=#{$ctx->docId} | type={$ctx->entityTypeId}");

    // --- ваша логика ---

} catch (\Throwable $e) {
    BpLog::error('my_script_name', '❌ Fatal: ' . $e->getMessage(), [
        'line' => $e->getLine(),
        'file' => basename($e->getFile()),
    ]);
}
```

---

## §6.6 Расположение файлов библиотеки

```
/local/bproc/
  lib/
    BpLog.php              — логирование (два канала)
    BpContext.php          — контекст документа (entityTypeId, docId, state)
    BpStorage.php          — SQL чтение/запись UF-полей
    BpConfigValidator.php  — валидация конфига при старте
  widget/
    bootstrap.php          — инициализация виджет-хелперов
    render/
      ProgressRenderer.php
      AlertsRenderer.php
      TimelineRenderer.php
      StagesRenderer.php
      ApprovalsRenderer.php
      RightsRenderer.php
      TeamRenderer.php
      EfficiencyRenderer.php
  logs/                    — файловые логи (создаётся автоматически)
  config_bp_constants.php  — коды UF-полей по entityTypeId
  config_rights.php        — профили и правила прав
  config_process_steps.php — конфиги процессов (steps, circles, roles)
  sys_setup_folders.php    — создание дерева папок
  sys_apply_rights.php     — применение прав по конфигу
  sys_widget_render.php    — рендер HTML-виджета
  tpl_widget_v7.html       — HTML-шаблон виджета
  tpl_widget_v7_css.html   — CSS виджета
```
