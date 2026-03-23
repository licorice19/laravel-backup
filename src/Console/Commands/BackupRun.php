<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\BackupDatabaseJob;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--only-db : Create a backup of the database only}
        {--with-files : Include files in the backup}';

    protected $description = 'Create a backup of the database and files';

    public function handle(): int
    {
        $this->info('Starting backup creation...');

        if ($this->option('with-files')) {
            config(['backup.include_files' => true]);
        }

        try {
            BackupDatabaseJob::dispatchSync();
            
            $this->info('Backup successfully created');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error while creating backup: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
