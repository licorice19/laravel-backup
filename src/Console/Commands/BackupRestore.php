<?php

namespace Licorice19\Backup\Console\Commands;

use Licorice19\Backup\Jobs\RestoreDatabaseJob;
use Licorice19\Backup\Services\BackupService;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {filename : Имя файла бекапа}
        {--force : Пропустить подтверждение}
        {--db-only : Восстановить только базу данных}';

    protected $description = 'Восстановить данные из бекапа';

    public function handle(BackupService $backupService): int
    {
        $filename = $this->argument('filename');

        $info = $backupService->getBackupInfo($filename);

        if (!$info) {
            $this->error("Бекап не найден: {$filename}");
            return Command::FAILURE;
        }

        $this->info("Информация о бекапе:");
        $this->line("  Файл: {$info['filename']}");
        $this->line("  Размер: {$info['size']}");
        $this->line("  База данных: " . ($info['has_database'] ? 'Да' : 'Нет'));
        $this->line("  Файлы: " . ($info['has_files'] ? 'Да' : 'Нет'));

        if ($info['manifest']) {
            $this->line("  Создан: " . ($info['manifest']['created_at'] ?? 'N/A'));
            $this->line("  Окружение: " . ($info['manifest']['app_env'] ?? 'N/A'));
            $this->line("  Драйвер БД: " . ($info['manifest']['driver'] ?? 'N/A'));
        }

        $this->newLine();
        $this->warn('ВНИМАНИЕ! Восстановление заменит текущие данные.');
        $this->warn('Будет создан бекап текущей базы данных перед восстановлением.');

        if (!$this->option('force')) {
            if (!$this->confirm('Продолжить восстановление?')) {
                $this->info('Операция отменена.');
                return Command::SUCCESS;
            }
        }

        $this->info('Начинаю восстановление...');

        try {
            RestoreDatabaseJob::dispatchSync($filename);

            $this->info("Восстановление завершено успешно!");
            $this->line("  Файл: {$filename}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка восстановления: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}