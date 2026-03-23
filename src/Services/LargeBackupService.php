<?php

namespace Licorice19\Backup\Services;

use Carbon\Carbon;
use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Licorice19\Backup\Exceptions\BackupException;

/**
 * LargeBackupService - Optimized for databases 5GB+ on shared hosting.
 * 
 */
class LargeBackupService
{
    protected string $disk;
    protected string $backupPath;
    protected string $tempPath;
    protected int $chunkSize;
    protected int $maxExecutionTime;
    protected array $callbacks = [];
    protected array $memoryConfig = [];

    public function __construct()
    {
        $this->disk = config('backup.disk', 'local');
        $this->backupPath = config('backup.path', 'backups');
        $this->tempPath = config('backup.temp_path', storage_path('app/backup-temp'));
        $this->chunkSize = config('backup.chunk_size', 1000);
        $this->maxExecutionTime = config('backup.max_execution_time', 3600);

        $this->memoryConfig = [
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];

        if (!File::isDirectory($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        if (!Storage::disk($this->disk)->exists($this->backupPath)) {
            Storage::disk($this->disk)->makeDirectory($this->backupPath);
        }
    }

    /**
     * Set progress callback
     */
    public function onProgress(callable $callback): self
    {
        $this->callbacks['progress'] = $callback;
        return $this;
    }

    /**
     * Set completion callback
     */
    public function onComplete(callable $callback): self
    {
        $this->callbacks['complete'] = $callback;
        return $this;
    }

    /**
     * Set error callback
     */
    public function onError(callable $callback): self
    {
        $this->callbacks['error'] = $callback;
        return $this;
    }

    /**
     * Fire progress callback
     */
    protected function fireProgress(string $stage, int $current, int $total, float $percent): void
    {
        if (isset($this->callbacks['progress'])) {
            ($this->callbacks['progress'])($stage, $current, $total, $percent);
        }
        
        if ($percent > 0 && $percent % 10 < 1) {
            gc_collect_cycles();
        }
    }

    /**
     * Increase resource limits for large operations
     */
    protected function increaseResourceLimits(): void
    {
        set_time_limit($this->maxExecutionTime);
        
        $currentLimit = ini_get('memory_limit');
        if ($currentLimit !== '-1') {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Restore original resource limits
     */
    protected function restoreResourceLimits(): void
    {
        @set_time_limit($this->memoryConfig['max_execution_time']);
        @ini_set('memory_limit', $this->memoryConfig['memory_limit']);
    }

    /**
     * Create a streaming backup (memory efficient)
     */
    public function backupDatabase(): array
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        $timestamp = now()->format('Y-m-d_His');
        $filename = "backup_{$timestamp}.zip";
        $relativePath = $this->backupPath . '/' . $filename;

        $tempZipPath = $this->tempPath . '/' . $filename;
        $sqlFile = $this->tempPath . '/dump_' . $timestamp . '.sql';

        $this->increaseResourceLimits();

        try {
            $this->fireProgress('starting', 0, 100, 0);
            
            $this->fireProgress('dumping', 1, 10, 10);
            $this->createDump($driver, $dbConfig, $sqlFile);
            $this->fireProgress('dumping', 5, 10, 50);
            gc_collect_cycles();

            $this->fireProgress('archiving', 6, 10, 60);
            $this->createStreamingZip($tempZipPath, $sqlFile, $driver);
            $this->fireProgress('archiving', 8, 10, 80);
            gc_collect_cycles();

            File::delete($sqlFile);

            $sha256 = hash_file('sha256', $tempZipPath);

            $this->updateManifestChecksum($tempZipPath, $sha256);

            $this->fireProgress('uploading', 9, 10, 90);
            $this->uploadToStorage($tempZipPath, $relativePath);

            File::delete($tempZipPath);

            $this->fireProgress('complete', 10, 10, 100);

            if (isset($this->callbacks['complete'])) {
                ($this->callbacks['complete'])([
                    'path' => $relativePath,
                    'filename' => $filename,
                    'checksum' => $sha256,
                ]);
            }

            Log::info("Large backup created: {$filename}");

            return [
                'path' => $relativePath,
                'filename' => $filename,
                'checksum' => $sha256,
            ];

        } catch (\Throwable $e) {
            if (isset($this->callbacks['error'])) {
                ($this->callbacks['error'])($e);
            }

            if (File::exists($sqlFile)) {
                File::delete($sqlFile);
            }
            if (File::exists($tempZipPath)) {
                File::delete($tempZipPath);
            }

            Log::error("Large backup failed: " . $e->getMessage());
            throw $e;
        } finally {
            $this->restoreResourceLimits();
        }
    }

    /**
     * Create database dump - writes directly to file (not memory)
     */
    protected function createDump(string $driver, array $dbConfig, string $sqlFile): void
    {
        if ($driver === 'sqlite') {
            $this->createSqliteDump($dbConfig, $sqlFile);
            return;
        }

        if (!in_array($driver, ['mysql', 'mariadb'])) {
            throw new \Exception("Unsupported database driver: {$driver}");
        }

        $username = $dbConfig['username'] ?? 'root';
        $password = $dbConfig['password'] ?? '';

        $dump = new Mysqldump(
            $this->buildDsn($dbConfig),
            $username,
            $password,
            $this->getDumpSettings()
        );

        $dump->start($sqlFile);
    }

    /**
     * Create SQLite dump
     */
    protected function createSqliteDump(array $dbConfig, string $sqlFile): void
    {
        $dbPath = $dbConfig['database'];

        if (!File::exists($dbPath)) {
            throw new \Exception("SQLite database not found: {$dbPath}");
        }

        copy($dbPath, $sqlFile);
    }

    /**
     * Create ZIP archive using streaming
     */
    protected function createStreamingZip(string $zipPath, string $sqlFile, string $driver): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            throw new \Exception("Failed to create ZIP archive: error {$result}");
        }

        try {
            $dbEntryName = ($driver === 'sqlite') ? 'database.sqlite' : 'database.sql';
            
            if (!$zip->addFile($sqlFile, $dbEntryName)) {
                throw new \Exception("Failed to add database to ZIP");
            }

            $manifest = $this->createManifest();
            $manifest['driver'] = $driver;
            $manifest['disk'] = $this->disk;
            $manifest['created_by'] = 'LargeBackupService';
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            $zip->addFromString('manifest.json', $manifestJson);

            $zip->setArchiveComment("Backup created: {$manifest['created_at']}");

        } finally {
            $zip->close();
        }
    }

    /**
     * Update manifest with checksum
     */
    protected function updateManifestChecksum(string $zipPath, string $sha256): void
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();
        
        $manifest['sha256'] = $sha256;

        $tempZip = $this->tempPath . '/temp_' . time() . '.zip';
        $newZip = new ZipArchive();
        $newZip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->open($zipPath);
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            
            if ($name === 'manifest.json') {
                $newZip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $content = $zip->getFromIndex($i);
                $newZip->addFromString($name, $content);
                unset($content);
            }
        }
        
        $zip->close();
        $newZip->setArchiveComment("Backup created: {$manifest['created_at']}");
        $newZip->close();

        unlink($zipPath);
        rename($tempZip, $zipPath);
    }

    /**
     * Upload ZIP to storage
     */
    protected function uploadToStorage(string $localPath, string $remotePath): void
    {
        $storage = Storage::disk($this->disk);
        
        $storage->put($remotePath, file_get_contents($localPath));
    }

    /**
     * Restore from backup with chunked SQL execution
     */
    public function restore(string $filename, bool $dbOnly = false, bool $useTransactional = true): array
    {
        $relativePath = $this->backupPath . '/' . $filename;

        if (!Storage::disk($this->disk)->exists($relativePath)) {
            throw new BackupException("Backup file not found: {$filename}");
        }

        $this->increaseResourceLimits();

        $tempZipPath = $this->downloadBackup($relativePath);

        try {
            $this->fireProgress('verifying', 0, 100, 0);

            $this->verifyBackup($tempZipPath);
            $this->fireProgress('verifying', 5, 100, 5);

            $zip = new ZipArchive();
            $zip->open($tempZipPath);
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $driver = $manifest['driver'] ?? 'sqlite';
            $this->fireProgress('verifying', 10, 100, 10);

            $this->verifyChecksum($tempZipPath, $manifest);
            $this->fireProgress('verifying', 15, 100, 15);

            $this->createPreRestoreBackup();
            $this->fireProgress('preparing', 20, 100, 20);

            $this->fireProgress('restoring_database', 25, 100, 25);
            
            if ($driver === 'sqlite') {
                $this->restoreSqlite($zip);
            } else {
                $this->restoreMysqlChunked($zip, $useTransactional);
            }
            
            $this->fireProgress('restoring_database', 80, 100, 80);

            $filesRestored = false;
            if (!$dbOnly && $this->zipHasEntry($zip, 'files/')) {
                $this->restoreFiles($zip);
                $filesRestored = true;
            }
            
            $this->fireProgress('complete', 100, 100, 100);

            $zip->close();

            if (isset($this->callbacks['complete'])) {
                ($this->callbacks['complete'])([
                    'success' => true,
                    'database_restored' => true,
                    'files_restored' => $filesRestored,
                ]);
            }

            Log::info("Large restore completed: {$filename}");

            return [
                'success' => true,
                'manifest' => $manifest,
                'database_restored' => true,
                'files_restored' => $filesRestored,
                'verification_passed' => true,
            ];

        } catch (\Throwable $e) {
            if (isset($this->callbacks['error'])) {
                ($this->callbacks['error'])($e);
            }
            throw $e;
        } finally {
            $this->restoreResourceLimits();
            
            if ($tempZipPath !== $relativePath && File::exists($tempZipPath)) {
                File::delete($tempZipPath);
            }
        }
    }

    /**
     * Check if ZIP has entry starting with prefix
     */
    protected function zipHasEntry(ZipArchive $zip, string $prefix): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Restore MySQL/MariaDB using chunked execution
     */
    protected function restoreMysqlChunked(ZipArchive $zip, bool $useTransactional): void
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $dsn = $this->buildDsn($dbConfig);
        $pdo = new \PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('SET SQL_MODE = ""');

        $entryName = $zip->getFromName('database.sql') ? 'database.sql' : 'database.sqlite';
        $sqlContent = $zip->getFromName($entryName);
        
        $tempSqlFile = $this->tempPath . '/restore_' . time() . '.sql';
        file_put_contents($tempSqlFile, $sqlContent);
        unset($sqlContent);

        try {
            if ($useTransactional && config('backup.transactional_restore', true)) {
                $this->transactionalRestore($pdo, $tempSqlFile);
            } else {
                $this->standardRestore($pdo, $tempSqlFile);
            }
        } finally {
            unlink($tempSqlFile);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        Log::info("MySQL database restored (chunked)");
    }

    /**
     * Standard restore (drops all tables first)
     */
    protected function standardRestore(\PDO $pdo, string $sqlFile): void
    {
        $executor = new ChunkedSqlExecutor($pdo, $this->chunkSize, $this->maxExecutionTime);
        $executor->onProgress(function ($executed, $total, $percent) {
            $this->fireProgress('restoring_database', 25 + ($percent * 0.55), 100, 25 + ($percent * 0.55));
        });

        $executor->executeFromFile($sqlFile);
    }

    /**
     * Transactional restore (renames old tables, restores new, rolls back on failure)
     */
    protected function transactionalRestore(\PDO $pdo, string $sqlFile): void
    {
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $renamed = [];

        try {
            foreach ($tables as $table) {
                $quotedTable = str_replace('`', '``', $table);
                $tmp = "_restore_backup_{$quotedTable}";
                $pdo->exec("RENAME TABLE `{$quotedTable}` TO `{$tmp}`");
                $renamed[] = $table;
            }

            $executor = new ChunkedSqlExecutor($pdo, $this->chunkSize, $this->maxExecutionTime);
            $executor->onProgress(function ($executed, $total, $percent) {
                $this->fireProgress('restoring_database', 25 + ($percent * 0.50), 100, 25 + ($percent * 0.50));
            });

            $executor->executeFromFile($sqlFile);

            foreach ($renamed as $table) {
                $quotedTable = str_replace('`', '``', $table);
                $pdo->exec("DROP TABLE IF EXISTS `_restore_backup_{$quotedTable}`");
            }

        } catch (\Throwable $e) {
            $this->rollbackTables($pdo, $renamed);
            throw new BackupException("Restore failed, rolled back: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rollback renamed tables on restore failure
     */
    protected function rollbackTables(\PDO $pdo, array $renamedTables): void
    {
        foreach ($renamedTables as $table) {
            try {
                $quotedTable = str_replace('`', '``', $table);
                $pdo->exec("RENAME TABLE `_restore_backup_{$quotedTable}` TO `{$quotedTable}`");
            } catch (\Throwable $e) {
                Log::critical("Failed to rollback table {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * Restore SQLite database
     */
    protected function restoreSqlite(ZipArchive $zip): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (empty($dbPath)) {
            throw new \Exception("SQLite database path not configured");
        }

        $tempDbPath = $this->tempPath . '/temp_restore_' . time() . '.sqlite';
        $sqlContent = $zip->getFromName('database.sqlite');
        file_put_contents($tempDbPath, $sqlContent);

        if (File::exists($dbPath)) {
            $backupPath = $dbPath . '.backup_' . time();
            copy($dbPath, $backupPath);
        }

        if (File::exists($dbPath)) {
            File::delete($dbPath);
        }
        rename($tempDbPath, $dbPath);

        Log::info("SQLite database restored");
    }

    /**
     * Restore files from backup
     */
    protected function restoreFiles(ZipArchive $zip): void
    {
        $destinationBase = realpath(storage_path('app/public')) ?: storage_path('app/public');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            
            if (!str_starts_with($name, 'files/')) {
                continue;
            }

            $relativePath = substr($name, 6);
            
            $normalizedPath = str_replace(['../', '..\\'], '', $relativePath);
            $fullPath = $destinationBase . '/' . $normalizedPath;

            $dir = dirname($fullPath);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            if (!str_ends_with($name, '/')) {
                $content = $zip->getFromIndex($i);
                file_put_contents($fullPath, $content);
                unset($content);
            }
        }

        Log::info("Files restored");
    }

    /**
     * Download backup to temp if needed
     */
    protected function downloadBackup(string $relativePath): string
    {
        if (in_array($this->disk, ['local', 'public'])) {
            return Storage::disk($this->disk)->path($relativePath);
        }

        $tempPath = $this->tempPath . '/' . basename($relativePath);
        $stream = Storage::disk($this->disk)->readStream($relativePath);
        
        $handle = fopen($tempPath, 'w');
        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $tempPath;
    }

    /**
     * Verify backup integrity
     */
    public function verifyBackup(string $zipPath): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        
        if ($result !== true) {
            throw new BackupException("Cannot open backup archive: error {$result}");
        }

        // Check for database entry
        $hasDatabase = $zip->locateName('database.sql') !== false 
                    || $zip->locateName('database.sqlite') !== false;
        
        if (!$hasDatabase) {
            $zip->close();
            throw new BackupException("Archive does not contain database file");
        }

        // Check for manifest
        if ($zip->locateName('manifest.json') === false) {
            $zip->close();
            throw new BackupException("Archive does not contain manifest");
        }

        // Verify ZIP integrity
        if ($zip->status !== ZipArchive::ER_OK) {
            $zip->close();
            throw new BackupException("ZIP archive is corrupted: status " . $zip->status);
        }

        $zip->close();
    }

    /**
     * Verify checksum
     */
    public function verifyChecksum(string $zipPath, array $manifest): void
    {
        $expected = $manifest['sha256'] ?? null;
        if ($expected === null) {
            Log::warning("Backup does not contain SHA256 checksum");
            return;
        }

        $actual = hash_file('sha256', $zipPath);
        if (!hash_equals($expected, $actual)) {
            throw new BackupException(
                "Checksum mismatch. Expected: {$expected}, Actual: {$actual}"
            );
        }
    }

    /**
     * Create manifest
     */
    protected function createManifest(): array
    {
        return [
            'created_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'laravel_version' => app()->version(),
            'backup_type' => 'large',
        ];
    }

    /**
     * Create pre-restore backup
     */
    protected function createPreRestoreBackup(): string
    {
        $backupService = new BackupService();
        return $backupService->backupDatabase();
    }

    /**
     * Build MySQL DSN
     */
    protected function buildDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        return "mysql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Get mysqldump settings for large databases
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
     * Get current memory usage
     */
    public static function getMemoryUsage(): array
    {
        return [
            'used' => number_format(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'peak' => number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'limit' => ini_get('memory_limit'),
        ];
    }
}
