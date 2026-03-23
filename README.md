# Laravel Backup Package

Пакет для создания и восстановления бекапов базы данных и файлов в Laravel.

## Возможности

- Создание бекапов базы данных (MySQL/MariaDB, SQLite)
- Восстановление из бекапов
- Автоматическая очистка старых бекапов
- Опциональное включение файлов в бекап
- Работа через очереди (Jobs)
- Информация о бекапах (манифест)

## Установка

```bash
composer require licorice19/laravel-backup
```

## Конфигурация

Опубликуйте конфигурационный файл:

```bash
php artisan vendor:publish --tag=backup-config
```

### Переменные окружения

```env
BACKUP_PATH=backups
BACKUP_DAYS_TO_KEEP=7
BACKUP_MAX_BACKUPS=10
BACKUP_INCLUDE_FILES=false
```

## Команды

### Создать бекап

```bash
php artisan backup:run
php artisan backup:run --with-files
```

### Список бекапов

```bash
php artisan backup:list
```

### Восстановить из бекапа

```bash
php artisan backup:restore backup_2026-03-23_095400.zip
php artisan backup:restore backup_2026-03-23_095400.zip --force
```

### Очистка старых бекапов

```bash
php artisan backup:clean
php artisan backup:clean --days=30 --max=20
```

## Использование через сервис

```php
use Licorice19\Backup\Services\BackupService;

$backupService = app(BackupService::class);

// Создать бекап
$path = $backupService->backupDatabase();

// Получить список бекапов
$backups = $backupService->listBackups();

// Восстановить из бекапа
$result = $backupService->restore('backup_2026-03-23_095400.zip');

// Очистить старые бекапы
$count = $backupService->cleanOldBackups();
```

## Использование через Jobs

```php
use Licorice19\Backup\Jobs\BackupDatabaseJob;
use Licorice19\Backup\Jobs\CleanOldBackupsJob;
use Licorice19\Backup\Jobs\RestoreDatabaseJob;

// Синхронно
BackupDatabaseJob::dispatchSync();

// Через очередь
BackupDatabaseJob::dispatch();

// Восстановление
RestoreDatabaseJob::dispatch('backup_2026-03-23_095400.zip');
```

## Планирование бекапов

Добавьте в `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Ежедневный бекап в 2:00 ночи
    $schedule->command('backup:run')->dailyAt('02:00');

    // Очистка старых бекапов раз в неделю
    $schedule->command('backup:clean')->weekly();
}
```

## Лицензия

MIT