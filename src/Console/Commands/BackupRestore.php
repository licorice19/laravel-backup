<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\RestoreDatabaseJob;
use Licorice19\Backup\Services\BackupService;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {filename : name of backup file}
        {--force : skip confirmation}
        {--db-only : restore only database}
        {--no-transactional : disable transactional restore}';

    protected $description = 'Restore data from backup';

    public function handle(BackupService $backupService): int
    {
        $filename = $this->argument('filename');

        $staleTables = $backupService->checkStaleRestoreTables();
        
        if (!empty($staleTables)) {
            $this->newLine();
            $this->error('Stale tables from unfinished restore detected:');
            foreach ($staleTables as $table) {
                $this->line("    - {$table}");
            }
            $this->warn('The previous restore operation may have been interrupted.');
            $this->warn('Run php artisan backup:cleanup-stale to clean them up.');
            $this->newLine();
            
            if (!$this->option('force') && !$this->confirm('Continue?')) {
                $this->info('Operation canceled.');
                return Command::FAILURE;
            }
        }

        $info = $backupService->getBackupInfo($filename);

        if (!$info) {
            $this->error("Backup not found: {$filename}");
            return Command::FAILURE;
        }

        $this->info("Backup Information:");
        $this->line("  File: {$info['filename']}");
        $this->line("  Size: {$info['size']}");
        $this->line("  Database: " . ($info['has_database'] ? 'Yes' : 'No'));
        $this->line("  Files: " . ($info['has_files'] ? 'Yes' : 'No'));

        if ($info['manifest']) {
            $this->line("  Created: " . ($info['manifest']['created_at'] ?? 'N/A'));
            $this->line("  Environment: " . ($info['manifest']['app_env'] ?? 'N/A'));
            $this->line("  DB Driver: " . ($info['manifest']['driver'] ?? 'N/A'));
            
            // Showing checksum availability
            $hasChecksum = isset($info['manifest']['sha256']);
            $this->line("  Checksum: " . ($hasChecksum ? 'Present (SHA256)' : 'None (old format)'));
        }

        $this->newLine();
        $this->warn('WARNING! Restoration will replace current data.');
        $this->warn('A backup of the current database will be created before restoration.');
        $this->info('The archive integrity will be verified before restoration.');

        if (!$this->option('force')) {
            if (!$this->confirm('Proceed with restoration?')) {
                $this->info('Operation canceled.');
                return Command::SUCCESS;
            }
        }

        $this->info('Starting restoration...');
        $this->line('  [1/3] Verifying archive...');

        try {
            $useTransactional = !$this->option('no-transactional');
            $result = $backupService->restore($filename, $this->option('db-only'), $useTransactional);

            if ($result['verification_passed']) {
                $this->line('  [2/3] ✓ Verification passed');
            }

            $this->line('  [3/3] ✓ Restoring data...');

            $this->newLine();
            $this->info("Restoration completed successfully!");
            $this->line("  File: {$filename}");
            $this->line("  Database: " . ($result['database_restored'] ? '✓ Restored' : '✗ Skipped'));
            $this->line("  Files: " . ($result['files_restored'] ? '✓ Restored' : '✗ Skipped'));

            return Command::SUCCESS;
        } catch (\Licorice19\Backup\Exceptions\BackupException $e) {
            $this->error("Restoration error: {$e->getMessage()}");
            
            if (str_contains($e->getMessage(), 'Checksum does not match')) {
                $this->warn('The archive was modified after the backup was created. It might be corrupted.');
            }
            
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Restoration error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
