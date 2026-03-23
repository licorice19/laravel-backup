<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\CleanOldBackupsJob;
use Illuminate\Console\Command;

class BackupClean extends Command
{
    protected $signature = 'backup:clean
        {--days= : Number of days to keep backups}
        {--max= : Maximum number of backups to keep}';

    protected $description = 'Remove old backups';

    public function handle(): int
    {
        $this->info('Cleaning up old backups...');

        if ($this->option('days')) {
            config(['backup.days_to_keep' => (int) $this->option('days')]);
        }

        if ($this->option('max')) {
            config(['backup.max_backups' => (int) $this->option('max')]);
        }

        try {
            CleanOldBackupsJob::dispatchSync();

            $this->info('Cleanup completed');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error while cleaning up backups: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
