<?php

namespace Licorice19\Backup\Services;

use Carbon\Carbon;
use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Licorice19\Backup\Exceptions\BackupException;
use ZipArchive;

class BackupService
{
    protected string $disk;
    protected string $backupPath;
    protected int $daysToKeep;
    protected int $maxBackups;
    protected string $tempPath;

    public function __construct()
    {
        $this->disk = config('backup.disk', 'local');
        $this->backupPath = config('backup.path', 'backups');
        $this->daysToKeep = config('backup.days_to_keep', 7);
        $this->maxBackups = config('backup.max_backups', 10);
        $this->tempPath = storage_path('app/backup-temp');

        if (!File::isDirectory($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        if (!Storage::disk($this->disk)->exists($this->backupPath)) {
            Storage::disk($this->disk)->makeDirectory($this->backupPath);
        }
    }

    /**
     * Get a Storage Disc Instance
     */
    protected function storage(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Create a database backup
     */
    public function backupDatabase(): string
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        $timestamp = now()->format('Y-m-d_His');
        $filename = "backup_{$timestamp}.zip";
        $relativePath = $this->backupPath . '/' . $filename;

        $tempZipPath = $this->tempPath . '/' . $filename;
        $sqlFile = $this->tempPath . '/temp_database.sql';

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \Exception("Failed to create ZIP archive: {$tempZipPath}");
            }

            if ($driver === 'sqlite') {
                $this->dumpSqlite($zip, $dbConfig);
            } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                $this->dumpMysql($zip, $dbConfig, $sqlFile);
            } else {
                throw new \Exception("Unsupported database driver: {$driver}");
            }

            if (config('backup.include_files', false)) {
                $this->addFilesToZip($zip);
            }

            $manifest = $this->createManifest();
            $manifest['driver'] = $driver;
            $manifest['disk'] = $this->disk;
            $manifest['sha256'] = 'CALCULATING';
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $zip->close();

            $sha256 = hash_file('sha256', $tempZipPath);
            
            $manifest['sha256'] = $sha256;

            $zip = new ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new \Exception("Failed to open archive to add checksum");
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();

            // Calculate final SHA256 (with real checksum in manifest)
            $finalSha256 = hash_file('sha256', $tempZipPath);
            $manifest['sha256'] = $finalSha256;

            // Update manifest one final time with the checksum that will match
            $zip = new ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new \Exception("Failed to open archive to add final checksum");
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();

            $fileStream = fopen($tempZipPath, 'r');
            $this->storage()->put($relativePath, $fileStream);
            fclose($fileStream);

            File::delete($tempZipPath);
            if (File::exists($sqlFile)) {
                File::delete($sqlFile);
            }

            Log::info("Backup successfully created: {$filename} on storage: {$this->disk}");

            return $relativePath;
        } catch (\Exception $e) {
            if (File::exists($sqlFile)) {
                File::delete($sqlFile);
            }
            if (File::exists($tempZipPath)) {
                File::delete($tempZipPath);
            }

            Log::error("Error while creating backup: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * SQLite database dump
     */
    protected function dumpSqlite(ZipArchive $zip, array $dbConfig): void
    {
        $dbPath = $dbConfig['database'];

        if (!File::exists($dbPath)) {
            throw new \Exception("SQLite database file not found: {$dbPath}");
        }

        $tempDbPath = $this->tempPath . '/temp_database.sqlite';
        copy($dbPath, $tempDbPath);

        $zip->addFile($tempDbPath, 'database.sqlite');

        register_shutdown_function(function () use ($tempDbPath) {
            if (File::exists($tempDbPath)) {
                @unlink($tempDbPath);
            }
        });
    }

    /**
     * MySQL/MariaDB database dump
     */
    protected function dumpMysql(ZipArchive $zip, array $dbConfig, string $sqlFile): void
    {
        $username = $dbConfig['username'] ?? 'root';
        $password = $dbConfig['password'] ?? '';

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
     * Clean storage from old backups
     */
    public function cleanOldBackups(): int
    {
        $deletedCount = 0;
        $files = collect($this->storage()->files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file, '.zip'))
            ->map(fn($file) => [
                'path' => $file,
                'timestamp' => $this->storage()->lastModified($file),
            ])
            ->sortByDesc('timestamp');

        $cutoffTimestamp = now()->subDays($this->daysToKeep)->timestamp;

        foreach ($files as $index => $file) {
            if ($file['timestamp'] < $cutoffTimestamp || $deletedCount >= $this->maxBackups) {
                $this->storage()->delete($file['path']);
                $deletedCount++;
                Log::info("Deleted old backup: " . basename($file['path']));
            }
        }

        $remainingFiles = collect($this->storage()->files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file, '.zip'))
            ->sortByDesc(fn($file) => $this->storage()->lastModified($file))
            ->values();

        if ($remainingFiles->count() > $this->maxBackups) {
            $toDelete = $remainingFiles->slice($this->maxBackups);
            foreach ($toDelete as $file) {
                $this->storage()->delete($file);
                $deletedCount++;
                Log::info("Old backup deleted (limit exceeded): " . basename($file));
            }
        }

        return $deletedCount;
    }

    /**
     * Get list of backups
     */
    public function listBackups(): array
    {
        $files = collect($this->storage()->files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file, '.zip'))
            ->map(fn($file) => [
                'name' => basename($file),
                'path' => $file,
                'size' => $this->formatBytes($this->storage()->size($file)),
                'date' => Carbon::createFromTimestamp($this->storage()->lastModified($file))->format('d.m.Y H:i:s'),
                'disk' => $this->disk,
            ])
            ->sortByDesc('date')
            ->values()
            ->toArray();

        return $files;
    }

    /**
     * Get size of all backups
     */
    public function getTotalSize(): string
    {
        $totalBytes = collect($this->storage()->files($this->backupPath))
            ->filter(fn($file) => str_ends_with($file, '.zip'))
            ->sum(fn($file) => $this->storage()->size($file));

        return $this->formatBytes((int) $totalBytes);
    }

    /**
     * Build a DSN to connect to the database
     */
    protected function buildDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];

        return "mysql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Dump settings
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
     * Add files to zip archive
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
     * Recursively add a directory to a ZIP
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $directory, array $exclude, string $zipPath = ''): void
    {
        $files = File::files($directory);

        foreach ($files as $file) {
            $relativePath = $zipPath . '/' . $file->getFilename();

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
     * Create a backup manifest
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
     * Restore from backup
     */
    public function restore(string $filename, bool $dbOnly = false, bool $useTransactional = true): array
    {
        $relativePath = $this->backupPath . '/' . $filename;

        if (!$this->storage()->exists($relativePath)) {
            throw new BackupException("Backup file not found: {$filename}");
        }

        $tempZipPath = $this->tempPath . '/' . $filename;
        $fileStream = $this->storage()->readStream($relativePath);
        file_put_contents($tempZipPath, stream_get_contents($fileStream));
        if (is_resource($fileStream)) {
            fclose($fileStream);
        }

        try {
            $this->verifyBackup($tempZipPath);

            $manifest = $this->readManifest($tempZipPath);
            $this->verifyChecksum($tempZipPath, $manifest);

            $driver = $manifest['driver'] ?? 'sqlite';

            $zip = new ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new BackupException("Failed to open archive: {$filename}");
            }

            $result = [
                'success' => true,
                'manifest' => $manifest,
                'database_restored' => false,
                'files_restored' => false,
                'verification_passed' => true,
            ];

            try {
                $this->createPreRestoreBackup();

                if ($driver === 'sqlite') {
                    $this->restoreSqlite($zip);
                } elseif (in_array($driver, ['mysql', 'mariadb'])) {
                    if ($useTransactional && config('backup.transactional_restore', true)) {
                        $sqlContent = $zip->getFromName('database.sql');
                        if ($sqlContent === false) {
                            throw new BackupException("The archive does not contain database.sql");
                        }
                        $this->restoreMysqlTransactional($tempZipPath, $sqlContent);
                    } else {
                        $this->restoreMysql($zip);
                    }
                }

                $result['database_restored'] = true;

                if (!$dbOnly && $zip->locateName('files/') !== false) {
                    $this->restoreFiles($zip);
                    $result['files_restored'] = true;
                }

                Log::info("Restoring from backup completed successfully: {$filename}");

            } catch (\Throwable $e) {
                $result['success'] = false;
                $result['error'] = $e->getMessage();
                Log::error("Restore Error: " . $e->getMessage());
                throw $e;
            } finally {
                $zip->close();
            }

            return $result;

        } catch (\Throwable $e) {
            throw $e;
        } finally {
            File::delete($tempZipPath);
        }
    }

    /**
     * Create a backup before restoring
     */
    protected function createPreRestoreBackup(): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $filename = "pre_restore_{$timestamp}.zip";
        $relativePath = $this->backupPath . '/' . $filename;

        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        $tempZipPath = $this->tempPath . '/' . $filename;

        $zip = new ZipArchive();
        $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($driver === 'sqlite') {
            $dbPath = $dbConfig['database'];
            if (File::exists($dbPath)) {
                $tempPath = $this->tempPath . '/temp_pre_restore.sqlite';
                copy($dbPath, $tempPath);
                $zip->addFile($tempPath, 'database.sqlite');
                $zip->close();
                File::delete($tempPath);
            } else {
                $zip->close();
            }
        } else {
            $zip->close();
        }

        if (File::exists($tempZipPath) && File::size($tempZipPath) > 0) {
            $fileStream = fopen($tempZipPath, 'r');
            $this->storage()->put($relativePath, $fileStream);
            fclose($fileStream);
        }

        File::delete($tempZipPath);

        Log::info("A backup was created before restoration.: {$filename}");

        return $relativePath;
    }

    /**
     * Restore SQLite database
     */
    protected function restoreSqlite(ZipArchive $zip): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (empty($dbPath)) {
            throw new \Exception("Filepath to SQLite file is not set.");
        }

        $tempDbPath = $this->tempPath . '/temp_restore.sqlite';
        $content = $zip->getFromName('database.sqlite');

        if ($content === false) {
            throw new \Exception("Archive doesn't contatin database.sqlite");
        }

        File::put($tempDbPath, $content);

        if (File::exists($dbPath)) {
            File::delete($dbPath);
        }

        File::move($tempDbPath, $dbPath);

        Log::info("SQLite database restored");
    }

    /**
     * Restore MySQL database
     */
    protected function restoreMysql(ZipArchive $zip): void
    {
        $sqlContent = $zip->getFromName('database.sql');

        if ($sqlContent === false) {
            throw new \Exception("Архив не содержит database.sql");
        }

        $tempSqlPath = $this->tempPath . '/temp_restore.sql';
        File::put($tempSqlPath, $sqlContent);

        try {
            $connection = config('database.default');
            $dbConfig = config("database.connections.{$connection}");

            $dsn = $this->buildDsn($dbConfig);
            $pdo = new \PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '');

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET SQL_MODE = ""');

            $this->executeSqlStatements($pdo, $sqlContent);

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            Log::info("MySQL database restored");

        } finally {
            if (File::exists($tempSqlPath)) {
                File::delete($tempSqlPath);
            }
        }
    }

    /**
     * Execute SQL statements from a dump
     */
    protected function executeSqlStatements(\PDO $pdo, string $sqlContent): void
    {
        $sql = preg_replace('/--.*$/m', '', $sqlContent);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                $prevChar = ($i > 0) ? $sql[$i - 1] : '';
                if ($prevChar !== '\\') {
                    $inString = false;
                }
            }

            if (!$inString && $char === ';') {
                $stmt = trim($current);
                if (!empty($stmt)) {
                    $statements[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $stmt = trim($current);
        if (!empty($stmt)) {
            $statements[] = $stmt;
        }

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
    }

    /**
     * Recover files
     */
    protected function restoreFiles(ZipArchive $zip): void
    {
        $destinationBase = realpath(storage_path('app/public'));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (str_starts_with($filename, 'files/')) {
                $relativePath = substr($filename, 6);

                $normalizedPath = str_replace(['../', '..\\', '/..', '\\..'], '', $relativePath);
                $fullPath = $destinationBase . '/' . $normalizedPath;
                $realFullPath = realpath(dirname($fullPath));

                if ($realFullPath === false || !str_starts_with($realFullPath, $destinationBase)) {
                    Log::warning("Skipped file with incorrect path: {$filename}");
                    continue;
                }

                if (str_ends_with($filename, '/')) {
                    if (!File::isDirectory($fullPath)) {
                        File::makeDirectory($fullPath, 0755, true);
                    }
                } else {
                    $content = $zip->getFromIndex($i);
                    $dirPath = dirname($fullPath);

                    if (!File::isDirectory($dirPath)) {
                        File::makeDirectory($dirPath, 0755, true);
                    }

                    File::put($fullPath, $content);
                }
            }
        }

        Log::info("Files are recovered successfuly");
    }

    /**
     * Get backup info
     */
    public function getBackupInfo(string $filename): ?array
    {
        $relativePath = $this->backupPath . '/' . $filename;

        if (!$this->storage()->exists($relativePath)) {
            return null;
        }

        $tempZipPath = $this->tempPath . '/' . $filename;
        $fileStream = $this->storage()->readStream($relativePath);
        file_put_contents($tempZipPath, stream_get_contents($fileStream));
        if (is_resource($fileStream)) {
            fclose($fileStream);
        }

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath) !== true) {
            File::delete($tempZipPath);
            return null;
        }

        $manifestContent = $zip->getFromName('manifest.json');
        $manifest = $manifestContent ? json_decode($manifestContent, true) : null;

        $info = [
            'filename' => $filename,
            'size' => $this->formatBytes($this->storage()->size($relativePath)),
            'has_database' => $zip->locateName('database.sql') !== false || $zip->locateName('database.sqlite') !== false,
            'has_files' => $zip->locateName('files/') !== false,
            'file_count' => 0,
            'manifest' => $manifest,
            'disk' => $this->disk,
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, '/') && !in_array($name, ['manifest.json', 'database.sql', 'database.sqlite'])) {
                $info['file_count']++;
            }
        }

        $zip->close();
        File::delete($tempZipPath);

        return $info;
    }

    /**
     * Get full path to backup file (for a local storage only)
     */
    public function getBackupFullPath(string $filename): ?string
    {
        $relativePath = $this->backupPath . '/' . $filename;

        if (!$this->storage()->exists($relativePath)) {
            return null;
        }
        if (in_array($this->disk, ['local', 'public'])) {
            return Storage::disk($this->disk)->path($relativePath);
        }

        return null;
    }

    /**
     * Download backup (Return file сontent)
     */
    public function downloadBackup(string $filename): ?string
    {
        $relativePath = $this->backupPath . '/' . $filename;

        if (!$this->storage()->exists($relativePath)) {
            return null;
        }

        return $this->storage()->get($relativePath);
    }

    /**
     * Verifying a ZIP archive before recovery
     */
    public function verifyBackup(string $zipPath): void
    {
        if (!file_exists($zipPath)) {
            throw new BackupException("File not found: {$zipPath}");
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CHECKCONS);

        if ($result !== true) {
            $errors = [
                ZipArchive::ER_NOZIP => 'It is not a zip archive.',
                ZipArchive::ER_INCONS => 'Archive is damaged (incorrect structure)',
                ZipArchive::ER_CRC => 'CRC checksum error',
                ZipArchive::ER_READ => 'File read error',
            ];
            throw new BackupException($errors[$result] ?? "Error zip: {$result}");
        }

        $hasDatabase = $zip->locateName('database.sql') !== false 
            || $zip->locateName('database.sqlite') !== false;
        
        if (!$hasDatabase) {
            $zip->close();
            throw new BackupException('Archive doesn\'t contain a database file');
        }

        $zip->close();
    }

    /**
     * Verifying the archive checksum
     */
    public function verifyChecksum(string $zipPath, array $manifest): void
    {
        $actual = hash_file('sha256', $zipPath);
        $expected = $manifest['sha256'] ?? null;

        if ($expected === null) {
            Log::warning("Backup {$zipPath} does not contain sha256 in the manifest");
            return;
        }

        if (!hash_equals($expected, $actual)) {
            throw new BackupException(
                "The checksum does not match.\nExpected: {$expected}\nActual:  {$actual}"
            );
        }
    }

    /**
     * Read manifest from file
     */
    public function readManifest(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BackupException("Failed to open archive: {$zipPath}");
        }

        $manifestContent = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestContent === false) {
            throw new BackupException("Archive doesn't contain manifest.json");
        }

        return json_decode($manifestContent, true) ?? [];
    }

    /**
     * Get list of current tables (excluding rollback temporary tables)
     */
    public function getCurrentTables(\PDO $pdo): array
    {
        $stmt = $pdo->query("SHOW TABLES");
        $all = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_filter($all, fn($t) => !str_starts_with($t, '_restore_backup_')));
    }

    /**
     * Check for "hanging" tables from an incomplete restore
     */
    public function checkStaleRestoreTables(): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return [];
        }

        try {
            $pdo = DB::getPdo();
            $all = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            return array_values(array_filter($all, fn($t) => str_starts_with($t, '_restore_backup_')));
        } catch (\Throwable $e) {
            Log::warning("Failed to check stale-tables: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Rollback 'restore'
     */
    protected function rollbackRestore(\PDO $pdo, array $renamedTables): void
    {
        $current = $this->getCurrentTables($pdo);
        foreach ($current as $table) {
            if (!str_starts_with($table, '_restore_backup_')) {
                try {
                    $quotedTable = str_replace('`', '``', $table);
                    $pdo->exec("DROP TABLE IF EXISTS `{$quotedTable}`");
                } catch (\Throwable) {
                }
            }
        }

        foreach ($renamedTables as $table) {
            try {
                $quotedTable = str_replace('`', '``', $table);
                $pdo->exec("RENAME TABLE `_restore_backup_{$quotedTable}` TO `{$quotedTable}`");
            } catch (\Throwable $e) {
                Log::critical("Failed to rollback {$table} ", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Transactional Restore for MySQL
     */
    protected function restoreMysqlTransactional(string $zipPath, string $sqlContent): void
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $dsn = $this->buildDsn($dbConfig);
        $pdo = new \PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $tables = $this->getCurrentTables($pdo);
        $renamed = [];

        try {
            foreach ($tables as $table) {
                $quotedTable = str_replace('`', '``', $table);
                $tmp = "_restore_backup_{$quotedTable}";
                $pdo->exec("RENAME TABLE `{$quotedTable}` TO `{$tmp}`");
                $renamed[] = $table;
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET SQL_MODE = ""');

            $this->executeSqlStatements($pdo, $sqlContent);

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            foreach ($renamed as $table) {
                $quotedTable = str_replace('`', '``', $table);
                $pdo->exec("DROP TABLE IF EXISTS `_restore_backup_{$quotedTable}`");
            }

            Log::info("MySQL database restored (transactionally)");

        } catch (\Throwable $e) {
            $this->rollbackRestore($pdo, $renamed);
            throw new BackupException("Failed to restore, files are recovered: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Format size
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
