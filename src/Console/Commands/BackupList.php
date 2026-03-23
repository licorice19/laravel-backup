<?php
namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Services\BackupService;use Illuminate\Console\Command;
class BackupList extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'Show list of backups';

    public function handle(BackupService $backupService): int
    {
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
        }

        $backups = $backupService->listBackups();

        if (empty($backups)) {
            $this->info('No backups found');

            return Command::SUCCESS;
        }

        $this->info("Total backups: " . count($backups));
        $this->info("Total size: " . $backupService->getTotalSize());
        $this->newLine();

        $this->table(
            ['File', 'Size', 'Date'],
            array_map(fn($backup) => [
                $backup['name'],
                $backup['size'],
                $backup['date'],
            ], $backups)
        );

        return Command::SUCCESS;
    }
}