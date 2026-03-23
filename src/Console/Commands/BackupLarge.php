<?php

namespace Licorice19\Backup\Console\Commands;

use Illuminate\Console\Command;
use Licorice19\Backup\Services\LargeBackupService;
use Licorice19\Backup\Services\LargeBackupService as LargeBackup;

class BackupLarge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:large 
                            {--only-db : Backup only database, skip files}
                            {--db-only : Same as --only-db}
                            {--with-files : Include files in backup}
                            {--no-clean : Skip cleanup of old backups}
                            {--dry-run : Show what would be done without doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a backup optimized for large databases (5GB+)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('===========================================');
        $this->info('  Large Database Backup');
        $this->info('===========================================');
        $this->newLine();

        $this->showMemoryInfo();

        $dbOnly = $this->option('db-only') || $this->option('only-db');
        $withFiles = $this->option('with-files');
        $noClean = $this->option('no-clean');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - No actions will be performed');
            $this->newLine();
        }

        $this->info('Configuration:');
        $this->table(
            ['Option', 'Value'],
            [
                ['Database Only', $dbOnly ? 'Yes' : 'No'],
                ['Include Files', $withFiles ? 'Yes' : 'No'],
                ['Auto-clean', $noClean ? 'No' : 'Yes'],
                ['Memory Limit', ini_get('memory_limit')],
                ['Temp Path', config('backup.temp_path', storage_path('app/backup-temp'))],
                ['Chunk Size', config('backup.chunk_size', 1000)],
            ]
        );
        $this->newLine();

        if ($dryRun) {
            $this->info('Would create a large backup...');
            return Command::SUCCESS;
        }

        try {
            $backupService = new LargeBackupService();

            // Set up progress bar
            $progressBar = $this->output->createProgressBar(100);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();

            $backupService->onProgress(function ($stage, $current, $total, $percent) use ($progressBar) {
                $messages = [
                    'starting' => 'Initializing...',
                    'dumping' => 'Creating database dump...',
                    'archiving' => 'Creating ZIP archive...',
                    'uploading' => 'Uploading to storage...',
                    'complete' => 'Done!',
                ];
                $progressBar->setProgress($percent, $messages[$stage] ?? $stage);
            });

            $this->newLine(2);

            $startTime = microtime(true);

            $result = $backupService->backupDatabase();

            $executionTime = round(microtime(true) - $startTime, 2);

            $this->newLine(2);
            $this->info('✓ Backup completed successfully!');
            $this->newLine();

            $this->table(
                ['Property', 'Value'],
                [
                    ['Filename', $result['filename']],
                    ['Path', $result['path']],
                    ['Checksum (SHA256)', substr($result['checksum'], 0, 32) . '...'],
                    ['Execution Time', $executionTime . 's'],
                    ['Memory Peak', LargeBackup::getMemoryUsage()['peak']],
                ]
            );

            if (!$noClean) {
                $this->newLine();
                $this->info('Cleaning up old backups...');
                $backupServiceClean = new \Licorice19\Backup\Services\BackupService();
                $deleted = $backupServiceClean->cleanOldBackups();
                $this->info("Deleted {$deleted} old backup(s)");
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine(2);
            $this->error('✗ Backup failed: ' . $e->getMessage());
            
            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Show current memory information
     */
    protected function showMemoryInfo(): void
    {
        $memory = LargeBackup::getMemoryUsage();
        
        $this->info('Memory Status:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Current Usage', $memory['used']],
                ['Peak Usage', $memory['peak']],
                ['Memory Limit', $memory['limit']],
            ]
        );
        $this->newLine();
    }
}
