<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Services\BackupService;
use Illuminate\Console\Command;

class BackupList extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'Показать список бекапов';

    public function handle(BackupService $backupService): int
    {
        $backups = $backupService->listBackups();

        if (empty($backups)) {
            $this->info('Бекапы не найдены');

            return Command::SUCCESS;
        }

        $this->info("Всего бекапов: " . count($backups));
        $this->info("Общий размер: " . $backupService->getTotalSize());
        $this->newLine();

        $this->table(
            ['Файл', 'Размер', 'Дата'],
            array_map(fn($backup) => [
                $backup['name'],
                $backup['size'],
                $backup['date'],
            ], $backups)
        );

        return Command::SUCCESS;
    }
}