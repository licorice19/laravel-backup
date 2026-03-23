<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\CleanOldBackupsJob;
use Illuminate\Console\Command;

class BackupClean extends Command
{
    protected $signature = 'backup:clean
        {--days= : Количество дней для хранения бекапов}
        {--max= : Максимальное количество бекапов}';

    protected $description = 'Удалить старые бекапы';

    public function handle(): int
    {
        $this->info('Очистка старых бекапов...');

        if ($this->option('days')) {
            config(['backup.days_to_keep' => (int) $this->option('days')]);
        }

        if ($this->option('max')) {
            config(['backup.max_backups' => (int) $this->option('max')]);
        }

        try {
            CleanOldBackupsJob::dispatchSync();

            $this->info('Очистка завершена');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка при очистке бекапов: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}