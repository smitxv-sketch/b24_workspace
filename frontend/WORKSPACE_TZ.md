# ТЗ: Enterprise Workspace — Bitrix24 Local App
**Версия:** 1.0 | **Дата:** 24.03.2026  
**Стек:** React 18 + Vite + Zustand | PHP 8+ (Bitrix24 Box)  
**Размещение:** Локальное приложение Bitrix24 (iframe)

---

## 1. Концепция и принципы

### 1.1 Что это
Интеллектуальная надстройка над Bitrix24. Пользователь видит все процессы в которых участвует — в одном месте, без поиска по CRM. Воркспейс не заменяет Битрикс24 — он агрегирует и направляет. Все действия (согласовать, принять задачу) — через переход в Б24.

### 1.2 Ключевые принципы
- **Config-driven:** ни один процесс, ни одна роль не захардкожены в React. Всё декларативно.
- **Role-first:** воркспейс принадлежит человеку, а не процессу. Один URL для всех.
- **Read-only actions:** кнопки в воркспейсе только открывают Б24. Никаких мутаций из React напрямую.
- **Apple-style UI:** карточки, воздух, sheet снизу. Цвет — только как сигнал.

### 1.3 Пользователи первой версии
- **Коммерческий директор** (`commercial_director`) — владелец процесса ТКП, видит все заявки, аналитику.
- Остальные роли (`gip`, `tech_director` и др.) — архитектурно заложены, реализуются итеративно.

---

## 2. Архитектура: конфиги

### 2.1 Файловая структура PHP

```
/local/bproc/
├── processes/
│   ├── deal_tkp.php                    ← конфиг БП (существующий)
│   └── deal_tkp_workspace.php          ← конфиг воркспейса процесса (новый)
│
├── workspace_roles/
│   ├── commercial_director.php         ← профиль КД
│   ├── gip.php
│   ├── tech_director.php
│   └── _default.php                    ← fallback
│
└── api/workspace/
    ├── bootstrap.php                   ← GET: инициализация сессии
    ├── process_items.php               ← GET: список заявок процесса
    ├── deal_detail.php                 ← GET: детали одной заявки
    └── analytics.php                  ← GET: аналитика
```

### 2.2 Конфиг воркспейса процесса (`deal_tkp_workspace.php`)

```php
<?php
return [
    'process_key' => 'deal_tkp',
    'title'       => 'Заявки ТКП',
    'icon'        => 'grid',           // grid | doc | clock | chart

    // Роли и что они видят в воркспейсе.
    // Ключи — имена ролей из deal_tkp.php → roles
    'workspace_roles' => [

        'responsible' => [
            'label'            => 'Владелец процесса',
            // Секции sheet (порядок = порядок отображения)
            'sections'         => ['alert', 'participants', 'timeline', 'tasks', 'discipline', 'feed'],
            // Папки из конфига folders — показывать как chips
            'folders'          => ['commercial', 'finance'],
            // Пилюли-фильтры в списке заявок
            'filters'          => ['overdue', 'action', 'wait'],
            // Видит ли экран аналитики
            'can_analytics'    => true,
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

        // Участник процесса без явной роли
        '_default' => [
            'label'         => 'Участник',
            'sections'      => ['alert', 'timeline'],
            'folders'       => [],
            'filters'       => [],
            'can_analytics' => false,
        ],
    ],

    // Поле сделки/СП с суммой (для аналитики)
    'amount_field' => 'UF_CONTRACT_AMOUNT',

    // Статусы для воронки аналитики
    // Берутся из crmStages + crmStagesSpecial конфига БП
    'funnel_statuses' => [
        'won'                  => ['label' => 'Взято в работу (WON)',    'color' => 'green'],
        'active'               => ['label' => 'Активных сейчас',         'color' => 'blue'],
        'customer_feedback'    => ['label' => 'Ожидание заказчика',      'color' => 'amber'],
        'customer_postponed'   => ['label' => 'Отложено заказчиком',     'color' => 'amber_dim'],
        'customer_rejected'    => ['label' => 'Отказ заказчика',         'color' => 'red'],
        'tech_decision_fail'   => ['label' => 'Откл. тех. директором',   'color' => 'red_dim'],
    ],
];
```

### 2.3 Профиль роли пользователя (`workspace_roles/commercial_director.php`)

```php
<?php
return [
    'role_key' => 'commercial_director',
    'label'    => 'Коммерческий директор',

    // Список процессов в навигации (порядок = порядок табов)
    'processes' => [
        'deal_tkp'        => ['label' => 'Заявки ТКП',  'icon' => 'grid'],
        'sp1070_contract' => ['label' => 'Договоры',     'icon' => 'doc'],
        'sp1058_project'  => ['label' => 'Проекты',      'icon' => 'clock'],
    ],

    // По каким процессам показывать аналитику
    'analytics_processes' => ['deal_tkp', 'sp1070_contract'],
];
```

### 2.4 Привязка пользователя к роли воркспейса

В Bitrix24 на пользователе создать поле:
- **Код:** `UF_WORKSPACE_ROLE`
- **Тип:** Строка (или список)
- **Значение:** ключ файла из `workspace_roles/` (например, `commercial_director`)

Если поле пустое → используется `_default.php`.

---

## 3. PHP API эндпоинты

### 3.1 `GET /local/bproc/api/workspace/bootstrap.php`

**Назначение:** Первый запрос при загрузке приложения. Возвращает всё необходимое для инициализации UI.

**Параметры:** нет (userId берётся из сессии Битрикс24)

**Логика:**
1. Определить `userId` текущего пользователя
2. Прочитать `UF_WORKSPACE_ROLE` → загрузить `workspace_roles/{role}.php`
3. Для каждого процесса из `processes` → проверить существование `{process}_workspace.php`
4. Через `RoleResolver` определить роль пользователя в каждом процессе
5. Подсчитать badge (кол-во заявок требующих внимания) для каждого процесса
6. Вернуть JSON

**Response:**
```json
{
  "user": {
    "id": 270,
    "name": "Эрик Панько",
    "workspace_role": "commercial_director",
    "workspace_role_label": "Коммерческий директор"
  },
  "nav": [
    {
      "key": "deal_tkp",
      "label": "Заявки ТКП",
      "icon": "grid",
      "badge": 2,
      "active": true
    },
    {
      "key": "sp1070_contract",
      "label": "Договоры",
      "icon": "doc",
      "badge": 0,
      "active": false,
      "disabled": true,
      "disabled_reason": "coming_soon"
    }
  ],
  "can_analytics": true,
  "debug": { ... }
}
```

---

### 3.2 `GET /local/bproc/api/workspace/process_items.php`

**Назначение:** Список заявок (ActionItems) для конкретного процесса.

**Параметры:**
- `process_key` (required) — например `deal_tkp`
- `filter` (optional) — `all` | `overdue` | `action` | `wait`

**Логика:**
1. Загрузить конфиг процесса и воркспейс-конфиг
2. Через `RoleResolver` определить роль пользователя
3. Найти все активные сделки/элементы СП этого процесса где пользователь участник
4. Для каждого вычислить: текущий шаг, статус, просрочку, флаги `is_overdue` / `needs_action` / `is_waiting`
5. Применить фильтр
6. Сортировать: `is_overdue` → `needs_action` → остальные → закрытые

**Вычисление флагов:**
```
is_overdue    = (now - stage.startedAt) > steps[currentStep].deadline_hours * 3600
                И currentStep имеет deadline_hours
needs_action  = userId ∈ resolveRole(currentStepResponsible)
                ИЛИ userId ∈ circles[currentStep].approvers
is_waiting    = steps[currentStep].type === 'wait'
```

**Response:**
```json
{
  "process_key": "deal_tkp",
  "user_process_role": "responsible",
  "workspace_config": {
    "sections": ["alert", "participants", "timeline", "tasks", "discipline", "feed"],
    "folders": ["commercial", "finance"],
    "filters": ["overdue", "action", "wait"]
  },
  "items": [
    {
      "id": "DEAL_4795",
      "entity_type": "deal",
      "entity_id": 4795,
      "title": "НТЦ-Геотехнология",
      "entity_url": "/crm/deal/details/4795/",
      "process_key": "deal_tkp",
      "current_step": "approval_tkp",
      "current_step_label": "Согласование ТКП",
      "version": 15,
      "is_rework": true,
      "progress_percent": 70,
      "is_overdue": true,
      "overdue_hours": 18,
      "needs_action": false,
      "is_waiting": false,
      "status_label": "Просрочено",
      "status_color": "red",
      "accent_color": "red",
      "deadline_hours": 48,
      "step_started_at": "2026-03-23T10:00:00",
      "participants_preview": [
        { "initials": "КИ", "color": "green", "status": "done" },
        { "initials": "ЭП", "color": "amber", "status": "wait" },
        { "initials": "ПА", "color": "amber", "status": "wait" },
        { "initials": "СВ", "color": "gray",  "status": "done" }
      ],
      "hint": "18 ч из 48 · просрочено",
      "section": "attention"
    }
  ],
  "sections": {
    "attention": { "label": "Требуют внимания", "count": 2 },
    "active":    { "label": "В работе",         "count": 3 },
    "closed":    { "label": "Закрыты",           "count": 2 }
  }
}
```

---

### 3.3 `GET /local/bproc/api/workspace/deal_detail.php`

**Назначение:** Полные данные для sheet одной заявки.

**Параметры:**
- `entity_id` (required)
- `process_key` (required)

**Логика:**
1. Загрузить JSON-стейт из `BpStorage::readJson`
2. Загрузить права из `BpStorage::readJson` (поле `*_F_RIGHTS`)
3. Определить роль пользователя → из воркспейс-конфига взять `sections` и `folders`
4. Для каждой секции собрать данные
5. Собрать alert: тип (red/blue/amber/green), заголовок, описание
6. Получить задачи через `CTasks::GetList` с фильтром по сделке
7. Получить ленту событий из `stages.*.history` + `approvals.*.history` + `nav.history`

**Response:**
```json
{
  "entity_id": 4795,
  "process_key": "deal_tkp",
  "title": "НТЦ-Геотехнология",
  "subtitle": "Сделка #4795 · Ответственный: Ком. директор · с 03.03.2026",
  "entity_url": "/crm/deal/details/4795/",
  "version": 15,
  "is_rework": true,
  "user_process_role": "responsible",
  "sections_to_show": ["alert", "participants", "timeline", "tasks", "discipline", "feed"],

  "chips": [
    { "label": "↗ Открыть в Битрикс24", "url": "/crm/deal/details/4795/", "style": "primary" },
    { "label": "КП →", "url": "https://...disk.../folder/12345/", "style": "default" },
    { "label": "Финансы →", "url": "https://...disk.../folder/12346/", "style": "default" }
  ],

  "alert": {
    "type": "red",
    "label": "Просрочено · 18 ч сверх дедлайна",
    "title": "Согласование ТКП v15 — ждут 2 голоса из 4",
    "description": "Кириллов проголосовал «за». Ожидают: Эрик Панько и Петров А. Перейдите в Битрикс24 чтобы проголосовать."
  },

  "participants": [
    {
      "initials": "КИ",
      "name": "Кириллов И.",
      "role_label": "Согласующий · за",
      "avatar_color": "green",
      "status": "done"
    }
  ],

  "timeline": [
    {
      "step_key": "assign_team",
      "label": "Назначение ответственных",
      "state": "done",
      "timing": "2 ч 15 мин · план 4 ч · 04.03",
      "is_overdue": false,
      "overdue_label": null,
      "voters": []
    },
    {
      "step_key": "approval_tkp",
      "label": "Согласование ТКП",
      "state": "current",
      "timing": "18 ч · план 48 ч",
      "is_overdue": true,
      "overdue_label": "просрочено",
      "voters": [
        { "name": "Кириллов",  "verdict": "ok",   "label": "за" },
        { "name": "Эрик",      "verdict": "wait",  "label": "ждёт" },
        { "name": "Петров",    "verdict": "wait",  "label": "ждёт" }
      ]
    },
    {
      "step_key": "customer_feedback",
      "label": "Обратная связь заказчика",
      "state": "pending",
      "timing": null,
      "is_overdue": false,
      "overdue_label": null,
      "voters": []
    }
  ],

  "tasks": [
    {
      "id": 1045,
      "title": "Подготовить тех. документацию",
      "state": "done",
      "assignee": "Иванов И.",
      "meta": "завершена 12.03 · 52 ч",
      "tag": null,
      "url": "/company/personal/user/270/tasks/task/view/1045/"
    },
    {
      "id": 1046,
      "title": "Финансовый расчёт ТКП",
      "state": "review",
      "assignee": "Ларина О.",
      "meta": "постановщик: вы · 13.03",
      "tag": "Ждёт проверки — откройте в Б24",
      "url": "/company/personal/user/270/tasks/task/view/1046/"
    }
  ],

  "discipline": [
    { "step_label": "Назначение",        "plan_hours": 4,  "fact_hours": 2,  "state": "ok",      "delta": -2 },
    { "step_label": "Тех. документация", "plan_hours": 48, "fact_hours": 52, "state": "overdue",  "delta": 4  },
    { "step_label": "Финансовая часть",  "plan_hours": 24, "fact_hours": 22, "state": "ok",       "delta": -2 },
    { "step_label": "Согласование ТКП",  "plan_hours": 48, "fact_hours": 18, "state": "running",  "delta": null }
  ],

  "feed": [
    {
      "avatar_initials": "КИ",
      "avatar_color": "green",
      "text": "Кириллов И. согласовал ТКП v15",
      "time_label": "10 мин назад"
    },
    {
      "avatar_initials": "БП",
      "avatar_color": "system",
      "text": "Запущен круг согласования ТКП v15 — 4 участника",
      "time_label": "18 ч назад"
    }
  ]
}
```

---

### 3.4 `GET /local/bproc/api/workspace/analytics.php`

**Параметры:**
- `process_key` (required)
- `period` — `month` | `year` (default: `month`)

**Логика:**
1. Проверить `can_analytics` для роли пользователя
2. Агрегировать сделки по `crmStages` + `crmStagesSpecial`
3. Суммировать `amount_field`
4. Считать среднее время по шагам (из `stages.*.history`)
5. Строить детализацию по активным заявкам

**Response:**
```json
{
  "period": "month",
  "period_label": "Март 2026",
  "funnel": [
    { "key": "won",               "label": "Взято в работу (WON)",  "color": "green",     "count": 12, "amount": 48000000 },
    { "key": "active",            "label": "Активных сейчас",        "color": "blue",      "count": 7,  "amount": 31000000 },
    { "key": "customer_feedback", "label": "Ожидание заказчика",     "color": "amber",     "count": 3,  "amount": 9000000  },
    { "key": "customer_rejected", "label": "Отказ заказчика",        "color": "red",       "count": 5,  "amount": 12000000 },
    { "key": "tech_decision_fail","label": "Откл. тех. директором",  "color": "red_dim",   "count": 2,  "amount": 4000000  }
  ],
  "step_averages": [
    { "step_key": "assign_team",  "label": "Назначение",         "plan_hours": 4,  "avg_hours": 2.6,  "state": "ok"      },
    { "step_key": "tech_docs",    "label": "Тех. документация",  "plan_hours": 48, "avg_hours": 52.0, "state": "overdue" },
    { "step_key": "finances",     "label": "Финансовая часть",   "plan_hours": 24, "avg_hours": 22.0, "state": "ok"      },
    { "step_key": "approval_tkp", "label": "Согласование ТКП",  "plan_hours": 48, "avg_hours": 55.0, "state": "overdue" }
  ],
  "deal_breakdown": [
    {
      "entity_id": 4795,
      "title": "#4795 v15",
      "steps": {
        "tech_docs":    { "hours": 52, "state": "overdue" },
        "finances":     { "hours": 22, "state": "ok"      },
        "approval_tkp": { "hours": 18, "state": "running" }
      }
    }
  ]
}
```

---

### 3.5 Диагностика

Все эндпоинты поддерживают `?debug=Y`.  
При `debug=Y` в ответ добавляется блок:

```json
"debug": {
  "execution_ms": 124,
  "user_id": 270,
  "workspace_role": "commercial_director",
  "process_role": "responsible",
  "queries_count": 7,
  "trace": [
    "BpRegistry: loaded deal_tkp",
    "RoleResolver: user 270 = responsible via ASSIGNED_BY_ID",
    "Found 7 active deals",
    "Computed overdue: deal_4795 (+18h)"
  ]
}
```

---

## 4. React-приложение

### 4.1 Стек

```
React 18
Vite 5
Zustand (стейт-менеджмент)
React Query (кэширование API-запросов, polling)
```

### 4.2 Структура файлов

```
src/
├── main.jsx
├── App.jsx
├── store/
│   └── useWorkspaceStore.js      ← Zustand store
├── api/
│   ├── client.js                 ← базовый fetch + error handling
│   ├── bootstrap.js
│   ├── processItems.js
│   ├── dealDetail.js
│   └── analytics.js
├── components/
│   ├── layout/
│   │   ├── TopNav.jsx            ← горизонтальная навигация
│   │   └── AppShell.jsx          ← обёртка с TopNav + screens
│   ├── deals/
│   │   ├── DealsScreen.jsx       ← экран списка заявок
│   │   ├── FilterPills.jsx       ← пилюли фильтров
│   │   ├── SectionLabel.jsx      ← заголовок секции
│   │   └── DealCard.jsx          ← карточка заявки
│   ├── sheet/
│   │   ├── Sheet.jsx             ← sheet-контейнер с анимацией
│   │   ├── SheetHeader.jsx       ← название + мета + close
│   │   ├── SheetChips.jsx        ← chips (Открыть в Б24, папки)
│   │   ├── AlertBanner.jsx       ← красный/синий/янтарный баннер
│   │   ├── ParticipantsRow.jsx   ← ряд участников
│   │   ├── ProcessTimeline.jsx   ← таймлайн шагов
│   │   ├── TaskList.jsx          ← список задач
│   │   ├── DisciplineBlock.jsx   ← свёртываемая дисциплина
│   │   └── EventFeed.jsx         ← лента событий
│   └── analytics/
│       ├── AnalyticsScreen.jsx
│       ├── PeriodSwitch.jsx
│       ├── FunnelChart.jsx
│       ├── StepAvgChart.jsx
│       └── DealBreakdownTable.jsx
└── utils/
    ├── colors.js                 ← color map (red/blue/amber/green → CSS)
    ├── formatters.js             ← форматирование дат, часов, сумм
    └── bx24.js                  ← BX24 JS SDK helpers
```

---

## 5. Компоненты: спецификация

### 5.1 `useWorkspaceStore` (Zustand)

```js
{
  // Bootstrap
  user: null,
  nav: [],
  canAnalytics: false,
  isBootstrapped: false,

  // Текущий процесс
  activeProcessKey: null,
  workspaceConfig: null,    // sections, filters, folders для текущего процесса

  // Список заявок
  items: [],
  sections: {},
  activeFilter: 'all',
  isLoadingItems: false,

  // Sheet
  openDealId: null,
  openProcessKey: null,
  dealDetail: null,
  isLoadingDetail: false,
  isSheetOpen: false,

  // Analytics
  analyticsData: null,
  analyticsPeriod: 'month',
  isLoadingAnalytics: false,

  // Actions
  setActiveProcess: (key) => ...,
  setFilter: (filter) => ...,
  openSheet: (entityId, processKey) => ...,
  closeSheet: () => ...,
  setAnalyticsPeriod: (period) => ...,
}
```

---

### 5.2 `App.jsx`

```jsx
// Логика:
// 1. При монтировании → вызвать bootstrap API
// 2. Записать в store user, nav, canAnalytics
// 3. Установить activeProcessKey = первый не-disabled таб
// 4. Рендерить AppShell
// 5. Polling: каждые 30 сек обновлять process_items для activeProcessKey

// Props: нет
// State: из useWorkspaceStore
// При ошибке bootstrap → показать ErrorScreen с кнопкой Retry
```

---

### 5.3 `TopNav`

```
Props: нет (читает из store)

Рендер:
- Левая часть: массив nav из store → для каждого рендерить tab
  - tab: icon + label + badge (если badge > 0)
  - активный: синий фон
  - disabled: opacity 0.38, pointer-events none, тег "скоро"
  - tab "Аналитика": рендерить только если canAnalytics === true
- Правая часть: уведомление (иконка колокола с красной точкой) + аватар + имя/роль пользователя

Поведение:
- Клик на таб → store.setActiveProcess(key) → перезагрузить items
- Аналитика — отдельный таб всегда последний если canAnalytics

Размер: height 48px, border-bottom 0.5px
```

---

### 5.4 `DealsScreen`

```
Props: нет (читает из store)

Рендер:
- PageHeader: title из workspaceConfig.title + subtitle "N активных · M требуют внимания"
- FilterPills: рендерить только фильтры из workspaceConfig.filters
  + всегда добавлять "Все" первым
- Список: итерировать по sections (attention → active → closed)
  Для каждой секции:
    - SectionLabel (если в секции есть items после фильтра)
    - DealCard для каждого item

Поведение:
- activeFilter меняется через store.setFilter
- При смене фильтра → фильтровать items на клиенте (не перезагружать API)
  Логика фильтра:
    'overdue' → item.is_overdue === true
    'action'  → item.needs_action === true
    'wait'    → item.is_waiting === true
    'all'     → все
```

---

### 5.5 `DealCard`

```
Props: { item }  ← объект из items[]

Рендер:
- Цветная полоска слева (item.accent_color → colors.js)
- Заголовок: item.title + если item.is_rework → бейдж "↩ v{version}"
- ID строка: #{entity_id}
- Шаг: "Сейчас: {current_step_label}" + доп. контекст
- Progress bar: item.progress_percent, цвет = item.accent_color
- Footer: стек аватаров (item.participants_preview) + item.hint
- Тег статуса: item.status_label, цвет item.status_color

Поведение:
- onClick → store.openSheet(item.entity_id, item.process_key)
- :hover → box-shadow
- :active → scale(0.989)
```

---

### 5.6 `Sheet`

```
Props: нет (читает из store)

Рендер:
- Overlay: fixed, blur(6px), rgba(0,0,0,.28)
  onClick вне sheet → store.closeSheet()
- Sheet контейнер: border-radius 22px 22px 0 0, max-height 88vh, overflow-y auto
- Handle: 38×4px серая полоска
- Содержимое: динамически по store.dealDetail.sections_to_show

Секции (рендерить только если key присутствует в sections_to_show):
  'alert'        → <AlertBanner alert={detail.alert} />
  'participants' → <ParticipantsRow participants={detail.participants} />
  'timeline'     → <ProcessTimeline steps={detail.timeline} />
  'tasks'        → <TaskList tasks={detail.tasks} />
  'discipline'   → <DisciplineBlock rows={detail.discipline} />
  'feed'         → <EventFeed events={detail.feed} />

Анимация:
- isSheetOpen false → translateY(100%)
- isSheetOpen true  → translateY(0), transition 0.38s cubic-bezier(.25,.46,.45,.94)
- При открытии: overflow:hidden на body (нет скролла под sheet)

Состояния:
- isLoadingDetail === true → показать скелетон внутри sheet (не блокировать overlay)
```

---

### 5.7 `AlertBanner`

```
Props: { alert: { type, label, title, description } }

type → цвет:
  'red'   → bg rgba(255,59,48,.07),  border rgba(255,59,48,.2)
  'blue'  → bg rgba(0,113,227,.07),  border rgba(0,113,227,.18)
  'amber' → bg rgba(255,149,0,.07),  border rgba(255,149,0,.2)
  'green' → bg rgba(52,199,89,.07),  border rgba(52,199,89,.2)

Если alert === null → не рендерить

Рендер:
- label: 9px uppercase (цвет по type)
- title: 13px font-weight 600
- description: 11px, color #6e6e73
```

---

### 5.8 `ProcessTimeline`

```
Props: { steps: TimelineStep[] }

TimelineStep: {
  step_key, label, state, timing,
  is_overdue, overdue_label,
  voters: [{ name, verdict, label }]
}

state → точка:
  'done'    → зелёная #34c759
  'current' → синяя #0071e3, glow 0 0 0 3px rgba(0,113,227,.15)
              если is_overdue → красная #ff3b30, glow rgba(255,59,48,.15)
  'pending' → серая #e5e5ea

Рендер строки:
- точка + вертикальная линия (кроме последней)
- label: .now (синий/красный если overdue), .pend (серый)
- timing: 10px серый + overdue_label в красной пилюле
- voters: только если voters.length > 0
  verdict 'ok' → зелёная пилюля
  verdict 'wait' → янтарная пилюля
```

---

### 5.9 `DisciplineBlock`

```
Props: { rows: DisciplineRow[] }

DisciplineRow: { step_label, plan_hours, fact_hours, state, delta }

state:
  'ok'      → fact зелёный, delta зелёный со знаком минус
  'overdue' → fact красный bold, delta красный со знаком плюс
  'running' → fact янтарный с "…", delta = "идёт"

Рендер:
- Toggle строка "Дисциплина по заявке" + стрелка ▼/▲
- При раскрытии → таблица 4 колонки: Шаг | План | Факт | Δ
- Шапка таблицы: серый фон, 9px uppercase
- Начальное состояние: свёрнуто
```

---

### 5.10 `AnalyticsScreen`

```
Props: нет (читает из store)

Рендер:
- Title + subtitle
- PeriodSwitch: "Март 2026" | "С начала года"
  onChange → store.setAnalyticsPeriod → перезагрузить analytics API

- FunnelChart: горизонтальные бары
  Props: { funnel: FunnelItem[] }
  Каждая строка: label (168px) + bar (flex:1, высота 22px) + сумма
  Ширина bar пропорциональна count относительно max count
  Цвет bar: color из funnel_statuses конфига
  Сумма: форматировать через Intl (₽ X млн)

- StepAvgChart: двойные бары план/факт
  Props: { step_averages: StepAvg[] }
  Серый bar = план, цветной = факт
  Если avg_hours > plan_hours → цветной bar красный, иначе зелёный
  Значение справа: avg_hours с ▲ если overdue

- DealBreakdownTable
  Props: { deal_breakdown, step_averages }
  Колонки: Заявка + по одной на каждый шаг из step_averages
  Ячейка: числовое значение, цвет по state
  Пустые ячейки (шаг ещё не дошёл): "—" серым
```

---

## 6. Цветовая система (`colors.js`)

```js
export const ACCENT_COLORS = {
  red:       { stripe: '#ff3b30', tag_bg: 'rgba(255,59,48,.1)',   tag_text: '#ff3b30' },
  blue:      { stripe: '#0071e3', tag_bg: 'rgba(0,113,227,.1)',   tag_text: '#0071e3' },
  amber:     { stripe: '#ff9500', tag_bg: 'rgba(255,149,0,.1)',   tag_text: '#ff9500' },
  green:     { stripe: '#34c759', tag_bg: 'rgba(52,199,89,.1)',   tag_text: '#34c759' },
  gray:      { stripe: '#c7c7cc', tag_bg: 'rgba(0,0,0,.06)',     tag_text: '#86868b' },
  red_dim:   { stripe: '#ff3b30', tag_bg: 'rgba(255,59,48,.06)',  tag_text: '#ff3b30', opacity: 0.6 },
  amber_dim: { stripe: '#ff9500', tag_bg: 'rgba(255,149,0,.06)',  tag_text: '#ff9500', opacity: 0.6 },
};

export const ALERT_COLORS = {
  red:   { bg: 'rgba(255,59,48,.07)',  border: 'rgba(255,59,48,.2)',  label: '#ff3b30' },
  blue:  { bg: 'rgba(0,113,227,.07)', border: 'rgba(0,113,227,.18)', label: '#0071e3' },
  amber: { bg: 'rgba(255,149,0,.07)', border: 'rgba(255,149,0,.2)',  label: '#ff9500' },
  green: { bg: 'rgba(52,199,89,.07)', border: 'rgba(52,199,89,.2)',  label: '#34c759' },
};

export const AVATAR_COLORS = {
  green:  { bg: 'rgba(52,199,89,.15)',  text: '#34c759' },
  blue:   { bg: 'rgba(0,113,227,.12)', text: '#0071e3' },
  amber:  { bg: 'rgba(255,149,0,.15)', text: '#ff9500' },
  red:    { bg: 'rgba(255,59,48,.12)', text: '#ff3b30' },
  gray:   { bg: 'rgba(0,0,0,.06)',    text: '#86868b' },
  system: { bg: 'rgba(0,0,0,.05)',    text: '#aeaeb2' },
};
```

---

## 7. Форматтеры (`formatters.js`)

```js
// Часы → "2 ч", "52 ч", "18 ч…"
formatHours(hours, isRunning = false)

// Дата → "04.03", "03.03.2026"
formatDate(isoString, short = true)

// Относительное время → "10 мин назад", "18 ч назад", "4 дня назад"
formatRelativeTime(isoString)

// Сумма → "₽ 48 млн", "₽ 4.5 млн"
formatAmount(rubles)

// Прогресс → число 0-100
computeProgress(currentStepIndex, totalSteps)
```

---

## 8. API клиент (`api/client.js`)

```js
const BASE = '/local/bproc/api/workspace';

async function apiFetch(endpoint, params = {}) {
  const url = new URL(BASE + endpoint, window.location.origin);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

  const res = await fetch(url, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });

  if (!res.ok) throw new Error(`API error: ${res.status}`);
  const data = await res.json();
  if (data.error) throw new Error(data.error);
  return data;
}

// Добавить ?debug=Y если localStorage.getItem('ws_debug') === '1'
```

---

## 9. Инициализация Bitrix24 (`bx24.js`)

```js
// Приложение регистрируется в Б24 как локальное.
// BX24 JS SDK доступен через window.BX24

export function initBX24() {
  return new Promise((resolve) => {
    if (window.BX24) {
      BX24.init(() => resolve());
    } else {
      // fallback для разработки вне iframe
      resolve();
    }
  });
}

// Открыть сущность в слайдере Б24
export function openSlider(url) {
  if (window.BX24) {
    BX24.openApplication({ url });
  } else {
    window.open(url, '_blank');
  }
}

// Получить текущего пользователя (fallback для dev)
export function getCurrentUserId() {
  return window.BX24?.getUserOption?.('userId') || null;
}
```

---

## 10. Polling и обновления

```
- bootstrap: загружается один раз при старте
- process_items: перезагружается при
    а) смене активного процесса
    б) каждые 30 сек (polling)
    в) вручную (кнопка или pull-to-refresh)
- deal_detail: загружается при открытии sheet, кэшируется 60 сек
- analytics: загружается при входе на экран, при смене периода

Polling реализовать через useInterval hook:
  useInterval(() => store.reloadItems(), 30_000)
  Останавливать polling когда sheet открыт (нет смысла обновлять список)
```

---

## 11. Состояния загрузки и ошибок

```
Skeleton компонент — показывать вместо DealCard пока isLoadingItems:
  - 3 прямоугольника с shimmer-анимацией
  - Размер как у DealCard

Sheet loading:
  - Показывать spinner в центре sheet
  - Не закрывать sheet при перезагрузке

Ошибки API:
  - Toast снизу экрана: "Ошибка загрузки. Попробуйте ещё раз."
  - Кнопка Retry
  - Не ломать весь UI
```

---

## 12. Шаги реализации (рекомендуемый порядок)

```
1. Создать Vite + React проект, настроить Zustand
2. Реализовать api/client.js + bx24.js
3. Реализовать bootstrap.php (минимальный, с заглушками)
4. Реализовать App.jsx + TopNav — навигация работает
5. Реализовать DealsScreen + DealCard — список работает (моковые данные)
6. Реализовать Sheet + все секции — sheet работает (моковые данные)
7. Подключить process_items.php — реальные данные в списке
8. Подключить deal_detail.php — реальные данные в sheet
9. Реализовать AnalyticsScreen — экран аналитики
10. Подключить analytics.php — реальные данные в аналитике
11. Зарегистрировать как приложение в Б24, тест в iframe
12. Polling + обработка ошибок
```

---

## 13. Регистрация приложения в Bitrix24

```
Тип: Локальное приложение
URL: /local/app/workspace/index.html (или через Vite build → /local/app/workspace/dist/)
Размещение: Добавить в левое меню или на главную страницу
Права: CRM (чтение сделок и СП), Задачи (чтение), Диск (чтение), Пользователи (чтение)
```

---

## 14. Что НЕ входит в v1

- Действия из воркспейса (всё только через Б24)
- Drag-and-drop виджетов
- Push-уведомления (WebSocket)
- Мобильная версия
- Другие процессы кроме deal_tkp
- Редактирование задач из воркспейса

---

## 15. Как добавить новый процесс (инструкция для будущего)

```
1. Создать /local/bproc/processes/{process_key}_workspace.php
   (скопировать deal_tkp_workspace.php как шаблон)

2. Добавить process_key в нужные workspace_roles/*.php

3. Создать ActionProvider для процесса
   (реализовать ActionProviderInterface)

4. Зарегистрировать в BpRegistry

5. React-код не трогать — новый таб появится автоматически
   через bootstrap API
```
