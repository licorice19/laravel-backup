<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\BackupDatabaseJob;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--only-db : Создать бекап только базы данных}
        {--with-files : Включить файлы в бекап}';

    protected $description = 'Создать бекап базы данных и файлов';

    public function handle(): int
    {
        $this->info('Начинаю создание бекапа...');

        if ($this->option('with-files')) {
            config(['backup.include_files' => true]);
        }

        try {
            BackupDatabaseJob::dispatchSync();
            
            $this->info('Бекап успешно создан');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при создании бекапа: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}