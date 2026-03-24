# 02 — Диск: папки, права, автоматизация

---

## §2.1 URL папки

### Проблема

`DetailUrl` и прямые ссылки `/disk/path/ID/` **не работают** для папок.  
`getPathInListing()` возвращает путь к **родителю**, не к самой папке.  
Кириллица в URL ломает ссылки без `rawurlencode()`.

### ✅ Рабочий шаблон

```php
CModule::IncludeModule('disk');

$folderId = {=Document:UF_CRM_FOLDER_ID};
$folder   = \Bitrix\Disk\Folder::getById($folderId);

if (!$folder) {
    $this->SetVariable('folder_url', 'ERROR: папка не найдена');
    return;
}

$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
$path       = $urlManager->getPathInListing($folder); // путь к РОДИТЕЛЮ!
$folderName = $folder->getName();

// Кодируем каждую часть пути по отдельности
$parts = explode('/', trim($path, '/'));
$encoded = [];
foreach ($parts as $part) {
    if (!empty($part)) {
        $encoded[] = rawurlencode($part);
    }
}
$encoded[] = rawurlencode($folderName); // добавляем саму папку

$url = 'https://ВАШ-ДОМЕН.ru/' . implode('/', $encoded) . '/';
$this->SetVariable('folder_url', $url);
```

### ❌ Что не работает

```php
$url = $folder->getDetailUrl();                  // только для файлов
$url = "https://site.ru/disk/path/{$folderId}/"; // ID не работает в URL
$url = 'https://site.ru/' . $path;               // кириллица без кодирования
```

---

## §2.2 Список подпапок

```php
// ✅ РАБОТАЕТ
$result = \Bitrix\Disk\Folder::getList([
    'filter' => ['PARENT_ID' => $parentFolderId]
    // Не добавлять TYPE — FolderTable::TYPE_FOLDER не существует во всех версиях
]);

while ($row = $result->fetch()) {
    $row['ID'];    // ID папки
    $row['NAME'];  // Название
}

// ❌ НЕ РАБОТАЕТ
$subs = $parentFolder->getChildren();            // требует параметры
$subs = $parentFolder->getChildren([]);          // работает только с объектом Folder
'TYPE' => \Bitrix\Disk\FolderTable::TYPE_FOLDER  // Class not found в ряде версий
```

---

## §2.3 Поиск папки по паттерну

Найти папку с максимальным номером в имени (например `v_5_Договор`):

```php
CModule::IncludeModule('disk');

$parentFolderId = {=Document:UF_CRM_PARENT_FOLDER};
$parentFolder   = \Bitrix\Disk\Folder::getById($parentFolderId);

if (!$parentFolder) {
    $this->SetVariable('max_folder_id', 0);
    return;
}

$result     = \Bitrix\Disk\Folder::getList(['filter' => ['PARENT_ID' => $parentFolderId]]);
$maxVersion = 0;
$maxId      = 0;
$maxName    = '';

while ($row = $result->fetch()) {
    if (preg_match('/^v_(\d+)_/', $row['NAME'], $m)) {
        $v = (int)$m[1];
        if ($v > $maxVersion) {
            $maxVersion = $v;
            $maxId      = $row['ID'];
            $maxName    = $row['NAME'];
        }
    }
}

$this->SetVariable('max_folder_id',   $maxId);
$this->SetVariable('max_folder_name', $maxName);
$this->SetVariable('max_version',     $maxVersion);
```

**Другие паттерны:**

```php
'/^v_(\d+)_/'              // v_5_Название
'/^Версия_(\d+)/i'         // Версия_5 (регистр неважен)
'/_v(\d+)$/'               // Название_v5
'/^(\d{4}-\d{2}-\d{2})_/'  // 2025-12-09_Название
"stripos(\$name, 'согласование') !== false"  // по подстроке
```

---

## §2.4 sys_setup_folders — Создание дерева папок

**Файл:** `/local/bproc/sys_setup_folders.php` (v3.0)  
**Запуск:** один раз в начале БП, шаг `setup`.

### Что делает

1. Читает дерево папок из `config_bp_constants.php` (секция `folders`)
2. Создаёт папки на Диске рекурсивно (пропускает уже существующие)
3. Опционально копирует файлы из родительской сущности (секция `import`)
4. Сохраняет маппинг `ключ → diskId` в UF-поле `UF_..._RIGHTS` в формате JSON

### Формат конфига папок

```php
// config_bp_constants.php
'folders' => [
    'docs'    => ['title' => 'Документы', 'parent' => '_root', 'isActive'  => true],
    'archive' => ['title' => 'Архив',     'parent' => '_root', 'isArchive' => true],
    'finance' => ['title' => 'Финансы',   'parent' => 'docs'],  // вложена в docs
],
'rootFolderId' => 12345,  // ID корневой папки на Диске
```

Флаги `isActive` и `isArchive` указывают, ID каких папок записать в отдельные поля документа.

### Import файлов из родителя

```php
'steps' => [
    'setup' => [
        'import' => [
            'parentField'        => 'UF_CRM_10_1764069553', // поле-привязка к родителю
            'sourceEntityTypeId' => 2,                       // тип родителя (2 = сделка)
            'folders'            => ['docs', 'finance'],     // какие папки копировать
        ],
    ],
],
```

Формат значения поля-привязки (как хранит Битрикс):
- Сделка → `D_123`
- Смарт-процесс → `T{hex(entityTypeId)}_{id}` — например `T42e_100` для SP 1070

Функция `_copyDiskFolderContents()` копирует содержимое рекурсивно, пропускает уже существующие файлы (проверка по имени).

### Результат в поле `UF_..._RIGHTS`

```json
{
  "profileKey": "deal_standard",
  "docFolderDiskId": 99001,
  "activeDiskId": 99002,
  "archiveDiskId": 99003,
  "folders": {
    "docs":    { "diskId": 99002, "title": "Документы", "parent": "_root", "currentRights": [] },
    "archive": { "diskId": 99003, "title": "Архив",     "parent": "_root", "currentRights": [] }
  },
  "createdAt": "09.12.2025 14:30",
  "updatedAt": "09.12.2025 14:30"
}
```

Этот JSON используется `sys_apply_rights` для поиска diskId папок по ключу.

---

## §2.5 Права доступа — базовые операции

### Выдать права одному пользователю

```php
CModule::IncludeModule('disk');

$folder = \Bitrix\Disk\Folder::getById($folderId);
if (!$folder) { return; }

$userId = {=Document:ASSIGNED_BY_ID};

$folder->addRight([
    'ACCESS_CODE' => 'U' . $userId,                          // U{id} — обязателен префикс!
    'TASK_ID'     => \Bitrix\Disk\RightsManager::TASK_EDIT,  // константа, не строка!
]);
```

### Выдать права нескольким пользователям

```php
$userIds = [123, 456, 789];
foreach ($userIds as $uid) {
    if ($uid > 0) {
        $folder->addRight([
            'ACCESS_CODE' => 'U' . $uid,
            'TASK_ID'     => \Bitrix\Disk\RightsManager::TASK_EDIT,
        ]);
    }
}
```

### Коды доступа

| Код | Кто получает права |
|-----|--------------------|
| `U{id}` | Конкретный пользователь |
| `G{id}` | Группа |
| `DR{id}` | Департамент с подотделами |
| `D{id}` | Департамент без подотделов |
| `AU` | Все авторизованные |
| `G2` | Все сотрудники |

### Уровни прав

| Константа | Уровень |
|-----------|---------|
| `RightsManager::TASK_READ` | Чтение |
| `RightsManager::TASK_EDIT` | Редактирование |
| `RightsManager::TASK_FULL` | Полный доступ |

### ❌ Частые ошибки с правами

```php
// Нет префикса U — права не применятся
'ACCESS_CODE' => $userId

// Строка вместо константы — ошибка
'TASK_ID' => 'edit'

// Неверный синтаксис addRight — ошибка
$folder->addRight($userId, 'edit')
```

---

## §2.6 sys_apply_rights — Применение прав по конфигу

**Файл:** `/local/bproc/sys_apply_rights.php` (v2.0)  
**Запуск:** на каждом шаге при смене этапа.

### Входные параметры (переменные БП)

| Переменная | Обязательна | Значения |
|------------|------------|---------|
| `step_key` | да | `prepare`, `approval_tkp`, … |
| `phase` | да | `on_start` \| `on_end` \| `on_success` \| `on_fail` \| `on_skip` |
| `profile_key` | нет | явный ключ профиля, иначе автоопределение |

### Что делает

1. Определяет профиль прав через `BpContext` → `categoryId` → `findRightsProfile()`
2. Берёт правила `rules[step_key][phase]` из конфига
3. Для каждой папки из JSON (`folders[key].diskId`): резолвит роли → access codes, вызывает `RightsManager::set()`
4. Обновляет `currentRights` в JSON-снапшоте и дописывает историю (хранится последние 100 записей)

### Требования

- Папки уже созданы через `sys_setup_folders` (маппинг в JSON обязателен)
- Конфиг прав описан в `config_rights.php`

### Пример конфига правил

```php
// config_rights.php
'rules' => [
    'prepare' => [
        'on_start' => [
            'docs' => [
                'responsible' => 'edit',
                'manager'     => 'read',
            ],
        ],
        'on_end' => [
            'docs' => [
                'responsible' => 'read',  // снижаем права после шага
            ],
        ],
    ],
],
```

### Зависимости

```
sys_apply_rights.php
  ├── BpLog.php          — логирование (WriteToTrackingService + файл)
  ├── BpContext.php      — определение entityTypeId, docId, конфига
  ├── BpStorage.php      — чтение/запись JSON в UF-поле
  ├── config_bp_constants.php — коды полей, константы
  └── config_rights.php  — профили и правила прав
```
