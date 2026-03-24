# 03 — CRM, смарт-процессы, файлы, задачи

---

## §3.1 BpStorage — надёжное чтение/запись UF-полей

**Файл:** `/local/bproc/lib/BpStorage.php`

### Зачем нужен

`Factory::getItem()` возвращает **кэш**. Если шаг А записал поле, а шаг Б читает через Factory — он получит старые данные. `BpStorage` всегда читает из БД напрямую через SQL.

### Маппинг таблиц

| entityTypeId | Таблица | Ключ |
|---|---|---|
| `2` (Сделка) | `b_uts_crm_deal` | `VALUE_ID` |
| Смарт-процесс | `b_crm_dynamic_items_{typeId}` | `ID` |

> Именно тут был баг «UTM/UTS»: читали из одной таблицы, писали в другую. Теперь маппинг в одном месте — `getTableInfo()`.

### API

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/bproc/lib/BpStorage.php';

// Читать строковое поле
$value = BpStorage::readField($entityTypeId, $docId, 'UF_CRM_MY_FIELD');

// Записать строковое поле
$ok = BpStorage::writeField($entityTypeId, $docId, 'UF_CRM_MY_FIELD', 'значение');

// Читать JSON → массив
$data = BpStorage::readJson($entityTypeId, $docId, 'UF_CRM_MY_FIELD');

// Записать массив → JSON
$ok = BpStorage::writeJson($entityTypeId, $docId, 'UF_CRM_MY_FIELD', $array);

// Самодиагностика
$errors = BpStorage::selfTest($entityTypeId, $docId, 'UF_CRM_MY_FIELD');
// [] = OK, иначе массив строк с ошибками
```

### Стратегия при сбое

SQL → если ошибка → Factory API fallback → логирует оба пути через `AddMessage2Log`.

---

## §3.2 Диагностика — что за сущность и какие у неё поля

### Определить entityTypeId текущего документа

```php
$root     = $this->GetRootActivity();
$docIdRaw = $root->GetDocumentId();
// $docIdRaw[2] выглядит как "SMART_PROCESS_1070_42"

preg_match('/_(\d+)$/', $docIdRaw[2], $m);
$docId = (int)($m[1] ?? 0);
```

### Вывести все UF-поля — полный диагностический скрипт

```php
<?php
use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;

Loader::includeModule('crm');

$root     = $this->GetRootActivity();
$docIdRaw = $root->GetDocumentId();
preg_match('/_(\d+)$/', $docIdRaw[2], $m);
$docId = (int)($m[1] ?? 0);

$container = Container::getInstance();
$log       = "=== ДИАГНОСТИКА #{$docId} ===\n";

// Перебираем типы — укажите актуальные для вашего портала
foreach ([2, 1070, 1074, 1075, 1076, 1077, 1078] as $typeId) {
    $factory = $container->getFactory($typeId);
    if (!$factory) continue;

    $item = $factory->getItem($docId);
    if (!$item) continue;

    $log .= "\n✓ ТИП {$typeId}: " . $factory->getEntityDescription() . "\n";

    foreach ($item->getData() as $key => $val) {
        if (strpos($key, 'UF_') !== 0) continue;
        $display = is_array($val)
            ? 'Array(' . count($val) . ')'
            : substr((string)$val, 0, 80);
        $log .= "  {$key} = {$display}\n";
    }
}

$log .= "\n=== КОНЕЦ ===\n";
$this->SetVariable('bp_render_log', $log);
```

---

## §3.3 Ссылки и комментарии в Timeline

### Почему не HTML

HTML в доп. полях CRM — **не работает**, теги отображаются как текст. Используйте BBCode или Timeline.

### ✅ Комментарий с кликабельной ссылкой в Timeline

```php
CModule::IncludeModule('crm');

$entityId     = {=Document:ID};
$entityTypeId = 1070;               // 2=Сделка, 1074=ваш смарт-процесс
$folderUrl    = "{=Variable:folder_url}";

$text = "📁 [URL={$folderUrl}]Открыть папку с документами[/URL]";

// Расширенный вариант с форматированием
$text = "📋 [B]Документация обновлена[/B]\n\n" .
        "[URL={$folderUrl}]📁 Открыть папку[/URL]\n" .
        "Версия: " . {=Variable:version_number};

\Bitrix\Crm\Timeline\CommentEntry::create([
    'ENTITY_TYPE_ID' => $entityTypeId,  // число, не строку!
    'ENTITY_ID'      => $entityId,
    'COMMENT'        => $text,
    'AUTHOR_ID'      => 1,              // 1 = от имени системы/админа
]);
```

### BBCode в заданиях БП (в поле «Описание задания»)

```
Документы готовы. Проверьте:
[URL={=Variable:folder_url}]📁 Открыть папку[/URL]

[B]Жирный[/B]  [I]Курсив[/I]  [U]Подчёркнутый[/U]
[USER=123] — упоминание пользователя
```

### ENTITY_TYPE_ID — справочник

| Сущность | Число |
|----------|-------|
| Лид | 1 |
| Сделка | 2 |
| Контакт | 3 |
| Компания | 4 |
| Смарт-процессы | 1070, 1074, 1075 … |

---

## §3.4 Файлы в CRM

### Получить список файлов из доп. поля

```php
CModule::IncludeModule('crm');

$dealId    = {=Document:ID};
$fieldCode = 'UF_CRM_1234567890';  // код вашего поля

global $USER_FIELD_MANAGER;
$fields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);
// Для лида:    'CRM_LEAD'
// Для контакта: 'CRM_CONTACT'
// Для компании: 'CRM_COMPANY'

$fileIds = [];
if (isset($fields[$fieldCode]['VALUE']) && !empty($fields[$fieldCode]['VALUE'])) {
    $fileIds = (array)$fields[$fieldCode]['VALUE']; // всегда приводим к массиву!
}

$this->SetVariable('file_ids_json', json_encode($fileIds));
$this->SetVariable('files_count',   count($fileIds));
```

### Информация о файле

```php
$fileId = 12345;
$info   = CFile::GetFileArray($fileId);

if ($info) {
    $name      = $info['ORIGINAL_NAME'];  // видимое имя (не хеш!)
    $size      = CFile::FormatSize($info['FILE_SIZE']); // "1.23 МБ"
    $mimeType  = $info['CONTENT_TYPE'];
    $path      = CFile::GetPath($fileId); // относительный путь
}
```

### Полная ссылка на файл (с кодированием)

```php
$fileId   = 12345;
$path     = CFile::GetPath($fileId);
$host     = str_replace(':443', '', $_SERVER['HTTP_HOST']);

$parts    = explode('/', $path);
$encoded  = [];
foreach ($parts as $part) {
    if (!empty($part)) $encoded[] = rawurlencode($part);
}
$fullUrl = 'https://' . $host . '/' . implode('/', $encoded);
```

### Копирование файла

```php
$fileArray = CFile::MakeFileArray($fileId);

if ($fileArray && ($fileArray['size'] ?? 0) > 0) {
    $newFileId = CFile::SaveFile($fileArray, "crm"); // "crm" | "main" | "disk"
    if ($newFileId > 0) {
        $this->SetVariable('new_file_id', (int)$newFileId);
    }
}
```

### Переименование при копировании

```php
$info      = CFile::GetFileArray($fileId);
$fileArray = CFile::MakeFileArray($fileId);

if ($fileArray && $info) {
    $ext  = pathinfo($info['ORIGINAL_NAME'], PATHINFO_EXTENSION);
    $base = pathinfo($info['ORIGINAL_NAME'], PATHINFO_FILENAME);
    $ver  = {=Variable:version_number};

    $fileArray['name'] = $base . '_' . date('d-m-Y') . '_v' . $ver . '.' . $ext;
    // Договор_09-12-2025_v2.pdf

    $newFileId = CFile::SaveFile($fileArray, "crm");
    $this->SetVariable('new_file_id', (int)$newFileId);
}
```

### Массовое копирование с переименованием

```php
$fileIds = json_decode("{=Variable:file_ids_json}", true);
$newIds  = [];
$version = {=Variable:version_number};

foreach ($fileIds as $fileId) {
    if ($fileId <= 0) continue;

    $info      = CFile::GetFileArray($fileId);
    $fileArray = CFile::MakeFileArray($fileId);

    if (!$fileArray || ($fileArray['size'] ?? 0) <= 0 || !$info) continue;

    $ext  = pathinfo($info['ORIGINAL_NAME'], PATHINFO_EXTENSION);
    $base = pathinfo($info['ORIGINAL_NAME'], PATHINFO_FILENAME);
    $fileArray['name'] = $base . '_v' . $version . '.' . $ext;

    $newId = CFile::SaveFile($fileArray, "crm");
    if ($newId > 0) $newIds[] = (int)$newId;
}

$this->SetVariable('new_files_json', json_encode($newIds));
$this->SetVariable('copied_count',   count($newIds));
```

### Формирование BBCode-ссылок на файлы

```php
$fileIds = json_decode("{=Variable:file_ids_json}", true);
$host    = str_replace(':443', '', $_SERVER['HTTP_HOST']);
$links   = '';

foreach ($fileIds as $fileId) {
    $info = CFile::GetFileArray($fileId);
    if (!$info) continue;

    $path   = CFile::GetPath($fileId);
    $parts  = array_filter(explode('/', $path));
    $url    = 'https://' . $host . '/' . implode('/', array_map('rawurlencode', $parts));

    $links .= "📎 [URL={$url}]{$info['ORIGINAL_NAME']}[/URL]" .
              " (" . CFile::FormatSize($info['FILE_SIZE']) . ")\n";
}

$this->SetVariable('file_links', $links);
```

---

## §3.5 Файлы из результата задачи

### Почему не UF_TASK_WEBDAV_FILES

`UF_TASK_WEBDAV_FILES` — **не использовать**: содержит файлы почтовых вложений, файлы родительской задачи, очищается при завершении, не обновляется в новом интерфейсе.

### Почему не CTasks::GetByID()

`CTasks::GetByID()` **не работает** в контексте БП из-за проблем с правами — возвращает `false`.

### ✅ Получить файлы из комментария-результата

```php
CModule::IncludeModule('tasks');
CModule::IncludeModule('forum');
CModule::IncludeModule('disk');

$taskId = {=Variable:task_id};

// CTaskItem(id, userId) — от имени пользователя 1 (имеет доступ ко всем задачам)
$oTask        = new CTaskItem($taskId, 1);
$taskData     = $oTask->getData(false);
$forumTopicId = $taskData['FORUM_TOPIC_ID'];

if (!$forumTopicId) {
    $this->SetVariable('error', 'Нет комментариев к задаче');
    return;
}

$rsMessages = CForumMessage::GetList(
    ['ID' => 'DESC'],
    ['TOPIC_ID' => $forumTopicId]
);

$resultFiles = [];

while ($msg = $rsMessages->Fetch()) {
    if (($msg['SERVICE_DATA'] ?? '') !== 'TASK_RESULT') continue; // маркер результата

    $res = \Bitrix\Disk\AttachedObject::getList([
        'filter' => [
            '=ENTITY_ID'   => $msg['ID'],
            '=ENTITY_TYPE' => 'Bitrix\Disk\Uf\ForumMessageConnector', // точно так!
        ]
    ]);

    while ($att = $res->fetch()) {
        $diskFile = \Bitrix\Disk\File::getById($att['OBJECT_ID']);
        if (!$diskFile) continue;

        $resultFiles[] = [
            'disk_id' => $att['OBJECT_ID'],
            'file_id' => $diskFile->getFileId(),  // для CFile
            'name'    => $diskFile->getName(),
            'size'    => $diskFile->getSize(),
        ];
    }

    break; // первый результат — достаточно
}

$this->SetVariable('result_files_json', json_encode($resultFiles));
$this->SetVariable('files_count', count($resultFiles));
```

### ✅ Скопировать файлы из результата в CRM

```php
// После получения $resultFiles (см. выше)
$newFileIds = [];

foreach ($resultFiles as $f) {
    $fileArray = CFile::MakeFileArray($f['file_id']);
    if (!$fileArray || ($fileArray['size'] ?? 0) <= 0) continue;

    $newId = CFile::SaveFile($fileArray, "crm");
    if ($newId > 0) $newFileIds[] = (int)$newId;
}

$this->SetVariable('task_files_json', json_encode($newFileIds));
```

---

## §3.6 Учёт времени

### Структура таблицы `b_tasks_elapsed_time`

| Поле | Тип | Описание |
|------|-----|----------|
| `USER_ID` | int | ID сотрудника |
| `TASK_ID` | int | ID задачи |
| `MINUTES` | int | Затрачено минут |
| `CREATED_DATE` | datetime | Дата записи |
| `COMMENT_TEXT` | text | Комментарий |

### SQL: отчёт с расчётом зарплаты за текущий месяц

```sql
SELECT
    u.ID                                                        AS user_id,
    CONCAT(u.NAME, ' ', u.LAST_NAME)                           AS user_name,
    ROUND(SUM(te.MINUTES) / 60, 2)                             AS total_hours,
    COALESCE(u.UF_HOURLY_RATE, 0)                              AS hourly_rate,
    ROUND((SUM(te.MINUTES) / 60) * COALESCE(u.UF_HOURLY_RATE, 0), 2) AS salary
FROM
    b_tasks_elapsed_time te
    INNER JOIN b_user u ON te.USER_ID = u.ID
WHERE
    te.CREATED_DATE >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
    AND te.CREATED_DATE < DATE_ADD(DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
GROUP BY
    u.ID, u.NAME, u.LAST_NAME, u.UF_HOURLY_RATE
ORDER BY
    salary DESC
```

### PHP: получение времени в БП

```php
CModule::IncludeModule('tasks');

$userId    = {=Document:ASSIGNED_BY_ID};
$startDate = date('Y-m-01');
$endDate   = date('Y-m-t 23:59:59');

$elapsed = \Bitrix\Tasks\Internals\Task\ElapsedTimeTable::getList([
    'filter' => [
        'USER_ID'         => $userId,
        '>=CREATED_DATE'  => $startDate,
        '<=CREATED_DATE'  => $endDate,
    ],
    'select' => ['MINUTES'],
]);

$totalMinutes = 0;
while ($row = $elapsed->fetch()) {
    $totalMinutes += $row['MINUTES'];
}

$totalHours = round($totalMinutes / 60, 2);

$this->SetVariable('worked_hours',   $totalHours);
$this->SetVariable('worked_minutes', $totalMinutes);
```

### Поле часовой ставки

Создать пользовательское поле для пользователей:  
Настройки → Настройки модулей → Главный модуль → Пользовательские поля

- Раздел: `Пользователи`
- Код: `UF_HOURLY_RATE`
- Тип: Число
- Права: Admin — чтение+запись, Руководитель — чтение, Сотрудник — нет

В SQL: `u.UF_HOURLY_RATE`  
В PHP: `floatval(\CUser::GetByID($userId)->Fetch()['UF_HOURLY_RATE'] ?? 0)`  
В БП: `{=Document:ASSIGNED_BY:UF_HOURLY_RATE}`
