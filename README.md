# Enterprise Workspace — Bitrix24 Local App

Интеллектуальная надстройка над Битрикс24 для управления процессами ТКП.

---

## Структура проекта

```
workspace_project/
├── php/
│   ├── api/workspace/          → Эндпоинты (копировать в /local/api/workspace/)
│   │   ├── bootstrap.php       → Инициализация сессии
│   │   ├── process_items.php   → Список заявок
│   │   ├── deal_detail.php     → Детали одной заявки
│   │   ├── analytics.php       → Аналитика
│   │   ├── _shared.php         → Общие хелперы (RoleResolver, вычисления)
│   │   └── test.html           → Тестовая страница эндпоинтов
│   └── bproc/
│       ├── processes/
│       │   └── deal_tkp_workspace.php → Конфиг воркспейса процесса ТКП
│       └── workspace_roles/
│           ├── commercial_director.php → Профиль КД
│           └── _default.php            → Профиль по умолчанию
└── frontend/                   → React + Vite приложение
    ├── src/
    │   ├── App.jsx             → Корень приложения
    │   ├── main.jsx            → Точка входа
    │   ├── index.css           → Глобальные стили
    │   ├── api/client.js       → API-клиент
    │   ├── store/              → Zustand store
    │   ├── components/
    │   │   ├── layout/TopNav.jsx
    │   │   ├── deals/DealsScreen.jsx
    │   │   ├── deals/DealCard.jsx
    │   │   ├── sheet/Sheet.jsx
    │   │   └── analytics/AnalyticsScreen.jsx
    │   └── utils/index.js      → Цвета, форматтеры
    ├── package.json
    ├── vite.config.js
    └── index.html
```

---

## Установка PHP-части

### 1. Скопировать файлы

```bash
# Эндпоинты
cp -r php/api/workspace/ /local/api/workspace/

# Конфиги воркспейса
cp php/bproc/processes/deal_tkp_workspace.php /local/bproc/processes/
cp php/bproc/workspace_roles/*.php /local/bproc/workspace_roles/
```

### 2. Создать папку для ролей воркспейса

```bash
mkdir -p /local/bproc/workspace_roles/
```

### 3. Создать UF-поле на пользователях

В Битрикс24: Настройки → Пользователи → Пользовательские поля
- Код: `UF_WORKSPACE_ROLE`
- Тип: Строка
- Значение для КД: `commercial_director`

### 4. Проверить эндпоинты

Открыть в браузере (с авторизованной сессией Б24):
```
/local/api/workspace/test.html
```

Кнопка `Cmd+Enter` / `Ctrl+Enter` — запустить запрос.

---

## Установка React-части

### 1. Установить зависимости

```bash
cd frontend
npm install
```

### 2. Настроить прокси для разработки

В `vite.config.js` заменить домен:
```js
target: 'https://YOUR-DOMAIN.bitrix24.ru',
```

### 3. Запустить dev-сервер

```bash
npm run dev
# → http://localhost:5173
```

### 4. Собрать для продакшена

```bash
npm run build
# → frontend/dist/
```

### 5. Разместить в Битрикс24

Скопировать содержимое `dist/` в `/local/app/workspace/`.

В `vite.config.js` раскомментировать:
```js
base: '/local/app/workspace/',
```

---

## Регистрация приложения в Битрикс24

Настройки → Разработчикам → Приложения → Добавить

- Тип: **Локальное приложение**
- URL обработчика: `https://YOUR-DOMAIN/local/app/workspace/`
- Права: CRM (чтение), Задачи (чтение), Диск (чтение), Пользователи (чтение)
- Разместить в меню: да

---

## Отладка

### Включить debug-режим в браузере

```js
localStorage.setItem('ws_debug', '1')
location.reload()
```

### Посмотреть логи PHP

```bash
tail -f /local/bproc/logs/ws_bootstrap.txt
tail -f /local/bproc/logs/ws_process_items.txt
tail -f /local/bproc/logs/ws_deal_detail.txt
```

### Тестовая страница эндпоинтов

`/local/api/workspace/test.html` — темный API-инспектор с подсветкой JSON.
Поддерживает debug=Y, автозаполнение entity_id из первого результата.

---

## Добавить новый процесс (чеклист)

1. Создать `/local/bproc/processes/{key}_workspace.php`
2. Добавить `key` в `/local/bproc/workspace_roles/commercial_director.php`
3. Добавить провайдер данных (по аналогии с deal_tkp логикой в process_items.php)
4. React не трогать — новый таб появится автоматически

---

## Что дорабатывать в Cursor

- [ ] `computeBadge()` в bootstrap.php — реальный подсчёт заявок
- [ ] Поддержка смарт-процессов в process_items.php (сейчас только сделки)
- [ ] `analytics.php` — добавить `stage_id` маппинг из реальных стадий
- [ ] Polling badge в TopNav — обновлять счётчики без перезагрузки
- [ ] Toast-уведомления об ошибках API
- [ ] Drag-and-drop виджетов (react-grid-layout)
- [ ] Роли GIP, tech_director — их workspace_roles файлы
