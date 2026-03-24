# 05 — Антипаттерны, ошибки, диагностика

> Сюда смотри когда что-то не работает и непонятно почему.

---

## §5.1 Быстрая диагностика

| Симптом | Первое что проверить |
|---------|---------------------|
| Переменная БП пустая | Используется ли `SetVariable`? Создана ли переменная в настройках БП? |
| `Class not found` | Подключён ли модуль? `CModule::IncludeModule('disk')` |
| URL с кириллицей не открывается | `rawurlencode()` на каждую часть пути? |
| Папка не найдена | Правильный ли `$folderId`? Не удалена ли папка? |
| HTML отображается как теги | Используйте Timeline + BBCode, не доп. поле |
| Права не применились | Есть ли префикс `U` в `ACCESS_CODE`? |
| Файлы задачи не найдены | `SERVICE_DATA == 'TASK_RESULT'`? Правильный `ENTITY_TYPE`? |
| УФ-поле читается устаревшим | Используется `BpStorage::readField()`, не `Factory::getItem()`? |
| Комментарий не попал в Timeline | `ENTITY_TYPE_ID` — число, не строка? |
| БП запускается но ничего не делает | Добавьте `WriteToTrackingService` в каждом шаге |

---

## §5.2 Антипаттерны — полная таблица

### Переменные БП

| ❌ Не работает | ✅ Работает | Причина |
|---|---|---|
| `{=Variable:x} = $val` | `$this->SetVariable('x', $val)` | `{=...}` — только синтаксис чтения |
| `$folder_url = $val` | `$this->SetVariable('folder_url', $val)` | Это обычная PHP-переменная |
| `$this->SetVariable('data', [1,2,3])` | `json_encode([1,2,3])` | Массив не сериализуется |
| `'{=Variable:name}'` | `"{=Variable:name}"` | Одинарные кавычки не подставляют значение |

---

### Диск — папки

| ❌ Не работает | ✅ Работает | Причина |
|---|---|---|
| `$folder->getDetailUrl()` | `UrlManager->getPathInListing()` + имя папки | `DetailUrl` только для файлов |
| `$folder->getChildren()` | `Folder::getList(['filter' => ['PARENT_ID' => $id]])` | Требует параметры |
| `FolderTable::TYPE_FOLDER` | Не использовать TYPE в фильтре | Класс не существует в ряде версий |
| `"https://site.ru/disk/path/{$id}/"` | Полный путь с названиями папок | ID не работает в URL |
| `urlencode($path)` | `rawurlencode($part)` для каждой части отдельно | `urlencode` кодирует `/` как `%2F` |
| `$urlManager->getPathInListing($f)` как готовый URL | + `rawurlencode($folder->getName())` в конце | API возвращает путь к **родителю** |

---

### Диск — права

| ❌ Не работает | ✅ Работает | Причина |
|---|---|---|
| `'ACCESS_CODE' => $userId` | `'ACCESS_CODE' => 'U' . $userId` | Нужен префикс типа |
| `'TASK_ID' => 'edit'` | `'TASK_ID' => RightsManager::TASK_EDIT` | Нужна константа, не строка |
| `$folder->addRight($uid, 'edit')` | `$folder->addRight(['ACCESS_CODE' => ..., 'TASK_ID' => ...])` | Неверная сигнатура |

---

### CRM и Timeline

| ❌ Не работает | ✅ Работает | Причина |
|---|---|---|
| `'<a href="...">Ссылка</a>'` в доп. поле | BBCode в задании / Timeline | HTML экранируется |
| `'ENTITY_TYPE_ID' => 'deal'` | `'ENTITY_TYPE_ID' => 2` | Нужно число, не строка |
| `Factory::getItem()` повторно после записи | `BpStorage::readField()` | Factory кэширует |

---

### Задачи

| ❌ Не работает | ✅ Работает | Причина |
|---|---|---|
| `CTasks::GetByID($id)` | `new CTaskItem($id, 1)` | Проблемы с правами в контексте БП |
| `$task['UF_TASK_WEBDAV_FILES']` | `AttachedObject::getList()` + `SERVICE_DATA = 'TASK_RESULT'` | Устаревшее поле, содержит мусор |
| `ENTITY_TYPE => 'ForumMessageConnector'` | `'Bitrix\Disk\Uf\ForumMessageConnector'` | Нужна полная строка с namespace |

---

## §5.3 Ошибки модулей

```php
// ❌ Class not found — не подключён модуль
$folder = \Bitrix\Disk\Folder::getById($id); // Fatal error

// ✅ Подключаем перед использованием
CModule::IncludeModule('disk');
CModule::IncludeModule('crm');
CModule::IncludeModule('tasks');
CModule::IncludeModule('forum');
CModule::IncludeModule('im');   // для CIMNotify
```

---

## §5.4 Ошибки синтаксиса PHP

```php
// Забытая точка с запятой
$a = 1      // ← Parse error
$b = 2;

// Кавычки внутри строки
$text = "Он сказал "привет"";       // ← Parse error
$text = "Он сказал \"привет\"";     // ✅
$text = 'Он сказал "привет"';       // ✅

// Переменная БП в одинарных кавычках
$x = '{=Variable:name}';    // ← строка, не значение
$x = "{=Variable:name}";    // ✅
```

---

## §5.5 Отладочные инструменты

### Вариант 1 — в переменную БП (видно в интерфейсе)

```php
$log = "step=1 | folderId={$folderId} | status=" . ($folder ? 'found' : 'not found');
$this->SetVariable('debug_log', $log);
```

### Вариант 2 — в лог БП (WriteToTrackingService)

```php
$root = $this->GetRootActivity();
$root->WriteToTrackingService("folderId={$folderId}, result={$result}");
// Видно в разделе «Журнал» бизнес-процесса
```

### Вариант 3 — в файл на сервере

```php
file_put_contents(
    $_SERVER['DOCUMENT_ROOT'] . '/local/logs/bp_debug.txt',
    date('Y-m-d H:i:s') . ' — ' . print_r($data, true) . "\n",
    FILE_APPEND
);
```

### Вариант 4 — диагностика UF-полей

Вставить в PHP-действие БП и запустить → результат в переменной `bp_render_log`.  
Полный код — в `03_CRM.md §3.2`.

---

## §5.6 Чеклист перед запуском кода в БП

- [ ] Все модули подключены (`disk`, `crm`, `tasks`, `forum`)
- [ ] Переменные БП созданы в настройках БП (не только в PHP)
- [ ] Числовые поля читаются без кавычек: `{=Document:ID}`
- [ ] Строковые поля — в двойных кавычках: `"{=Document:TITLE}"`
- [ ] URL: каждая часть пути через `rawurlencode()` по отдельности
- [ ] Права: `ACCESS_CODE = 'U' . $userId`, `TASK_ID` — константа
- [ ] Массивы сохраняются через `json_encode()`, читаются через `json_decode()`
- [ ] `try-catch` на критичные операции
- [ ] Есть проверка `if (!$object) { return; }` перед работой с объектом
