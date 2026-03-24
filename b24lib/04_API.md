# 04 — REST API проектов (api_projects.php)

---

## §4.1 Назначение и эндпоинт

`api_projects.php` — PHP-эндпоинт, который отдаёт список **активных проектов** (рабочих групп) с датами сдачи, статусами и количеством задач. Используется фронтендом таймлайна проектов.

**Расположение:** `/local/api/api_projects.php`  
**Метод:** `GET`  
**Ответ:** `application/json`

---

## §4.2 Авторизация

Используется **HMAC-кука** `timeline_auth`. Без неё — `401 Unauthorized`.

### Структура куки

```
timeline_auth = base64(payload).base64(HMAC-SHA256(payload, secret))
```

`payload` — JSON: `{ "iat": <timestamp_ms> }`. Срок действия — 24 часа.

### Как проверяется на сервере

```php
$authCookie = $_COOKIE['timeline_auth'] ?? null;
[$payloadB64, $signature] = explode('.', $authCookie);

$payload = json_decode(base64_decode($payloadB64), true);

// Проверка срока
if (time() * 1000 - $payload['iat'] > 86400 * 1000) → 401 Expired

// Проверка подписи
$expected = base64_encode(hash_hmac('sha256', $payloadB64, $serverPassword, true));
if (!hash_equals($expected, $signature)) → 401 Invalid Sig
```

`$serverPassword` берётся из переменной окружения `TIMELINE_PASSWORD`.

---

## §4.3 Логика запроса к Битрикс24

Эндпоинт делает несколько обращений к REST API через `fetchBitrix()`:

1. **`socialnetwork.api.workgroup.list`** — получает активные незакрытые группы (до 200)
2. **`user.get`** — получает имена владельцев (отдельным запросом, не через batch, чтобы избежать багов с массивами)
3. **`batch`** — для каждой группы одновременно запрашивает:
   - детали группы (`socialnetwork.api.workgroup.get`)
   - общее количество задач (`tasks.task.list`)
   - количество задач верхнего уровня (`tasks.task.list` с `PARENT_ID=0`)

Batch разбивается на чанки по **45 команд** (лимит Битрикса — 50, запас для безопасности).

---

## §4.4 Структура ответа

```json
[
  {
    "id": 42,
    "title": "Проект «Реконструкция»",
    "dueDate": "2025-03-15",
    "status": "IN_PROGRESS",
    "milestone": "Сдача проекта",
    "ownerName": "Иван Иванов",
    "totalTasks": 38,
    "topLevelTasks": 12,
    "url": "/workgroups/group/42/"
  }
]
```

### Поле `status`

| Значение | Когда |
|----------|-------|
| `IN_PROGRESS` | Активен, дата не просрочена |
| `COMPLETED` | Группа закрыта (`CLOSED = Y`) |
| `DELAYED` | Дата сдачи в прошлом |
| `RISK` | Название содержит `!` |

### Определение даты сдачи `dueDate`

Приоритет:
1. `UF_PLAN_COMPLITION_DATE` (кастомное поле группы, опечатка в названии сохранена)
2. `PROJECT_DATE_FINISH`
3. `DATE_FINISH`

Проекты без ни одной из этих дат **пропускаются** (не попадают в ответ).

---

## §4.5 Вспомогательные функции

### `fetchBitrix($method, $params)`

```php
function fetchBitrix($method, $params = []) {
    // POST на {webhookUrl}/{method}.json
    // При HTTP >= 400 → Exception
    // При error в ответе → Exception с error_description
    // Возвращает массив из json_decode
}
```

Webhook берётся из `getenv('BITRIX_WEBHOOK')`.

### `extractDateValue($field)`

Нормализует дату из любого формата:
- Строка `DD.MM.YYYY` → `YYYY-MM-DD`
- Строка ISO `YYYY-MM-DDTHH:MM:SS` → `YYYY-MM-DD`
- Массив `['VALUE' => ...]` → рекурсивно

---

## §4.6 Расширение под сделки

Чтобы получить аналогичные данные для **сделок** (не проектов), нужно:

**Шаг 1.** Заменить метод получения сущностей:

```php
// Вместо socialnetwork.api.workgroup.list
$dealsData = fetchBitrix('crm.deal.list', [
    'filter' => ['STAGE_SEMANTIC_ID' => 'P'], // P = в работе
    'select' => ['ID', 'TITLE', 'ASSIGNED_BY_ID', 'CLOSEDATE', 'UF_*'],
    'order'  => ['CLOSEDATE' => 'ASC'],
    'start'  => 0,
]);
```

**Шаг 2.** Адаптировать маппинг полей:

```php
$projects[] = [
    'id'            => (int)$deal['ID'],
    'title'         => $deal['TITLE'],
    'dueDate'       => substr($deal['CLOSEDATE'] ?? '', 0, 10),
    'status'        => /* логика по STAGE_SEMANTIC_ID */,
    'ownerName'     => $userMap[$deal['ASSIGNED_BY_ID']] ?? 'Не назначен',
    'url'           => '/crm/deal/details/' . $deal['ID'] . '/',
];
```

**Шаг 3.** Убрать batch-запросы задач (у сделок другая структура) или заменить на `crm.activity.list`.

---

## §4.7 Расширение под смарт-процессы

```php
// Получить элементы смарт-процесса (entityTypeId = 1070)
$items = fetchBitrix('crm.item.list', [
    'entityTypeId' => 1070,
    'filter'       => ['stageSemanticId' => 'P'],
    'select'       => ['id', 'title', 'assignedById', 'UF_*'],
]);

// Поля: $item['id'], $item['title'], $item['ufPlanComplitionDate']
// URL: /crm/type/1070/details/{id}/
```

---

## §4.8 Конфигурация

| Переменная окружения | Назначение | Дефолт |
|---------------------|-----------|--------|
| `BITRIX_WEBHOOK` | URL входящего вебхука | `https://your-domain.bitrix24.ru/rest/1/key/` |
| `TIMELINE_PASSWORD` | Секрет для HMAC-подписи куки | `your_secret_password` |

Задаются в `.env` или через переменные окружения сервера.
