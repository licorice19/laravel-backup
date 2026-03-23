<?php

namespace Licorice19\Backup\Services;

use Carbon\Carbon;
use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupService
{
    protected string $backupPath;
    protected int $daysToKeep;
    protected int $maxBackups;

    public function __construct()
    {
        $this->backupPath = storage_path('app/' . config('backup.path', 'backups'));
        $this->daysToKeep = config('backup.days_to_keep', 7);
        $this->maxBackups = config('backup.max_backups', 10);

        if (!File::isDirectory($this->backupPath)) {
            File::makeDirectory($this->backupPath, 0755, true);
        }
    }

    /**
     * Создать бекап базы данных
     */
    public function backupDatabase(): string
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        $timestamp = now()->format('Y-m-d_His');
        $filename = "backup_{$timestamp}.zip";
        $zipPath = $this->backupPath . '/' . $filename;

        // Временный файл для дампа
        $sqlFile = $this->backupPath . '/temp_database.sql';

        try {
            // Создаем ZIP-архив
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \Exception("Не удалось создать ZIP-архив: {$zipPath}");
            }

            // Дамп базы данных в зависимости от драйвера
            if ($driver === 'sqlite') {
                $this->dumpSqlite($zip, $dbConfig);
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                $this->dumpMysql($zip, $dbConfig, $sqlFile);
            } else {
                throw new \Exception("Неподдерживаемый драйвер базы данных: {$driver}");
            }

            // Добавляем файлы, если включено
            if (config('backup.include_files', false)) {
                $this->addFilesToZip($zip);
            }

            // Добавляем информацию о бекапе
            $manifest = $this->createManifest();
            $manifest['driver'] = $driver;
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $zip->close();

            // Удаляем временный файл
            if (File::exists($sqlFile)) {
                File::delete($sqlFile);
            }

            Log::info("Бекап успешно создан: {$filename}");

            return $zipPath;
        } catch (\Exception $e) {
            // Удаляем временные файлы при ошибке
            if (File::exists($sqlFile)) {
                File::delete($sqlFile);
            }
            if (File::exists($zipPath)) {
                File::delete($zipPath);
            }

            Log::error("Ошибка при создании бекапа: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Дамп SQLite базы данных
     */
    protected function dumpSqlite(ZipArchive $zip, array $dbConfig): void
    {
        $dbPath = $dbConfig['database'];

        if (!File::exists($dbPath)) {
            throw new \Exception("Файл базы данных SQLite не найден: {$dbPath}");
        }

        // Копируем файл БД во временный файл (чтобы избежать блокировок)
        $tempDbPath = $this->backupPath . '/temp_database.sqlite';
        copy($dbPath, $tempDbPath);

        $zip->addFile($tempDbPath, 'database.sqlite');

        // Удалим временный файл после завершения скрипта
        register_shutdown_function(function () use ($tempDbPath) {
            if (File::exists($tempDbPath)) {
                @unlink($tempDbPath);
            }
        });
    }

    /**
     * Дамп MySQL/MariaDB базы данных
     */
    protected function dumpMysql(ZipArchive $zip, array $dbConfig, string $sqlFile): void
    {
        $username = $dbConfig['username'] ?? 'root';
        $password = $dbConfig['password'] ?? '';

        // Дамп базы данных через mysqldump-php
        $dump = new Mysqldump(
            $this->buildDsn($dbConfig),
            $username,
            $password,
            $this->getDumpSettings()
        );
        $dump->start($sqlFile);

        $zip->addFile($sqlFile, 'database.sql');
    }

    /**
     * Очистить старые бекапы
     */
    public function cleanOldBackups(): int
    {
        $deletedCount = 0;
        $files = collect(File::files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file->getFilename(), '.zip'))
            ->sortByDesc('mtime');

        // Удаляем по количеству дней
        $cutoffDate = now()->subDays($this->daysToKeep);

        foreach ($files as $file) {
            $fileDate = Carbon::createFromTimestamp($file->getMTime());

            if ($fileDate->lt($cutoffDate) || $deletedCount >= $this->maxBackups) {
                File::delete($file->getPathname());
                $deletedCount++;
                Log::info("Удален старый бекап: {$file->getFilename()}");
            }
        }

        return $deletedCount;
    }

    /**
     * Получить список бекапов
     */
    public function listBackups(): array
    {
        $files = collect(File::files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file->getFilename(), '.zip'))
            ->map(fn($file) => [
                'name' => $file->getFilename(),
                'size' => $this->formatBytes($file->getSize()),
                'date' => Carbon::createFromTimestamp($file->getMTime())->format('d.m.Y H:i:s'),
            ])
            ->sortByDesc('date')
            ->values()
            ->toArray();

        return $files;
    }

    /**
     * Получить размер всех бекапов
     */
    public function getTotalSize(): string
    {
        $totalBytes = collect(File::files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file->getFilename(), '.zip'))
            ->sum(fn($file) => $file->getSize());

        return $this->formatBytes((int) $totalBytes);
    }

    /**
     * Построить DSN для подключения к БД
     */
    protected function buildDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];

        return "mysql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Настройки дампа
     */
    protected function getDumpSettings(): array
    {
        return [
            'include-tables' => [],
            'exclude-tables' => [],
            'compress' => Mysqldump::NONE,
            'no-data' => false,
            'add-drop-table' => true,
            'single-transaction' => true,
            'lock-tables' => false,
            'add-locks' => false,
            'extended-insert' => true,
            'disable-keys' => true,
            'skip-triggers' => false,
            'routines' => true,
            'hex-blob' => true,
            'events' => true,
        ];
    }

    /**
     * Добавить файлы в ZIP-архив
     */
    protected function addFilesToZip(ZipArchive $zip): void
    {
        $directories = config('backup.directories', []);
        $exclude = config('backup.exclude', []);

        foreach ($directories as $directory) {
            if (!File::isDirectory($directory)) {
                continue;
            }

            $this->addDirectoryToZip($zip, $directory, $exclude);
        }
    }

    /**
     * Рекурсивно добавить директорию в ZIP
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $directory, array $exclude, string $zipPath = ''): void
    {
        $files = File::files($directory);

        foreach ($files as $file) {
            $relativePath = $zipPath . '/' . $file->getFilename();

            // Проверяем исключения
            $shouldExclude = false;
            foreach ($exclude as $excluded) {
                if (str_contains($file->getPathname(), $excluded)) {
                    $shouldExclude = true;
                    break;
                }
            }

            if (!$shouldExclude) {
                $zip->addFile($file->getPathname(), 'files' . $relativePath);
            }
        }

        // Рекурсивно обходим поддиректории
        $directories = File::directories($directory);
        foreach ($directories as $subDir) {
            $dirName = basename($subDir);

            if (in_array($dirName, $exclude)) {
                continue;
            }

            $this->addDirectoryToZip($zip, $subDir, $exclude, $zipPath . '/' . $dirName);
        }
    }

    /**
     * Создать манифест бекапа
     */
    protected function createManifest(): array
    {
        return [
            'created_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * Восстановить из бекапа
     */
    public function restore(string $filename, bool $dbOnly = false): array
    {
        $zipPath = $this->backupPath . '/' . $filename;

        if (!File::exists($zipPath)) {
            throw new \Exception("Файл бекапа не найден: {$filename}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \Exception("Не удалось открыть архив: {$filename}");
        }

        // Читаем манифест
        $manifestContent = $zip->getFromName('manifest.json');
        if ($manifestContent === false) {
            throw new \Exception("Архив не содержит manifest.json");
        }

        $manifest = json_decode($manifestContent, true);
        $driver = $manifest['driver'] ?? 'sqlite';

        $result = [
            'success' => true,
            'manifest' => $manifest,
            'database_restored' => false,
            'files_restored' => false,
        ];

        try {
            // Создаем бекап текущей БД перед восстановлением
            $this->createPreRestoreBackup();

            // Восстанавливаем БД
            if ($driver === 'sqlite') {
                $this->restoreSqlite($zip);
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                $this->restoreMysql($zip);
            }

            $result['database_restored'] = true;

            // Восстанавливаем файлы
            if (!$dbOnly && $zip->locateName('files/') !== false) {
                $this->restoreFiles($zip);
                $result['files_restored'] = true;
            }

            Log::info("Восстановление из бекапа завершено: {$filename}");

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            Log::error("Ошибка восстановления: " . $e->getMessage());
            throw $e;
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Создать бекап перед восстановлением
     */
    protected function createPreRestoreBackup(): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $filename = "pre_restore_{$timestamp}.zip";
        $zipPath = $this->backupPath . '/' . $filename;

        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($driver === 'sqlite') {
            $dbPath = $dbConfig['database'];
            if (File::exists($dbPath)) {
                $tempPath = $this->backupPath . '/temp_pre_restore.sqlite';
                copy($dbPath, $tempPath);
                $zip->addFile($tempPath, 'database.sqlite');
            }
        }

        $zip->close();

        // Удаляем временный файл
        if (isset($tempPath) && File::exists($tempPath)) {
            File::delete($tempPath);
        }

        Log::info("Создан бекап перед восстановлением: {$filename}");

        return $zipPath;
    }

    /**
     * Восстановить SQLite базу данных
     */
    protected function restoreSqlite(ZipArchive $zip): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (empty($dbPath)) {
            throw new \Exception("Путь к SQLite базе данных не настроен");
        }

        // Извлекаем файл БД
        $tempDbPath = $this->backupPath . '/temp_restore.sqlite';
        $content = $zip->getFromName('database.sqlite');

        if ($content === false) {
            throw new \Exception("Архив не содержит database.sqlite");
        }

        File::put($tempDbPath, $content);

        // Заменяем текущую БД
        if (File::exists($dbPath)) {
            File::delete($dbPath);
        }

        File::move($tempDbPath, $dbPath);

        Log::info("SQLite база данных восстановлена");
    }

    /**
     * Восстановить MySQL базу данных
     */
    protected function restoreMysql(ZipArchive $zip): void
    {
        $sqlContent = $zip->getFromName('database.sql');

        if ($sqlContent === false) {
            throw new \Exception("Архив не содержит database.sql");
        }

        // Сохраняем во временный файл
        $tempSqlPath = $this->backupPath . '/temp_restore.sql';
        File::put($tempSqlPath, $sqlContent);

        try {
            $connection = config('database.default');
            $dbConfig = config("database.connections.{$connection}");

            // Подключаемся к БД
            $dsn = $this->buildDsn($dbConfig);
            $pdo = new \PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '');

            // Отключаем проверки внешних ключей
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET SQL_MODE = ""');

            // Выполняем SQL
            $pdo->exec($sqlContent);

            // Включаем проверки обратно
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            Log::info("MySQL база данных восстановлена");

        } finally {
            // Удаляем временный файл
            if (File::exists($tempSqlPath)) {
                File::delete($tempSqlPath);
            }
        }
    }

    /**
     * Восстановить файлы
     */
    protected function restoreFiles(ZipArchive $zip): void
    {
        $destinationBase = storage_path('app/public');
        $tempDir = $this->backupPath . '/temp_files';

        // Создаем временную директорию
        if (!File::isDirectory($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        // Извлекаем файлы
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (str_starts_with($filename, 'files/')) {
                $relativePath = substr($filename, 6); // Убираем 'files/'

                if (str_ends_with($filename, '/')) {
                    // Это директория
                    $dirPath = $destinationBase . '/' . $relativePath;
                    if (!File::isDirectory($dirPath)) {
                        File::makeDirectory($dirPath, 0755, true);
                    }
                } else {
                    // Это файл
                    $content = $zip->getFromIndex($i);
                    $filePath = $destinationBase . '/' . $relativePath;
                    $dirPath = dirname($filePath);

                    if (!File::isDirectory($dirPath)) {
                        File::makeDirectory($dirPath, 0755, true);
                    }

                    File::put($filePath, $content);
                }
            }
        }

        // Удаляем временную директорию
        if (File::isDirectory($tempDir)) {
            File::deleteDirectory($tempDir);
        }

        Log::info("Файлы восстановлены");
    }

    /**
     * Получить информацию о бекапе
     */
    public function getBackupInfo(string $filename): ?array
    {
        $zipPath = $this->backupPath . '/' . $filename;

        if (!File::exists($zipPath)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $manifestContent = $zip->getFromName('manifest.json');
        $manifest = $manifestContent ? json_decode($manifestContent, true) : null;

        $info = [
            'filename' => $filename,
            'size' => $this->formatBytes(File::size($zipPath)),
            'has_database' => $zip->locateName('database.sql') !== false || $zip->locateName('database.sqlite') !== false,
            'has_files' => $zip->locateName('files/') !== false,
            'file_count' => 0,
            'manifest' => $manifest,
        ];

        // Считаем файлы
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, '/') && !in_array($name, ['manifest.json', 'database.sql', 'database.sqlite'])) {
                $info['file_count']++;
            }
        }

        $zip->close();

        return $info;
    }

    /**
     * Форматировать размер файла
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
