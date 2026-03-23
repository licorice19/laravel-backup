<?php

namespace Licorice19\Backup\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackupCleanupStale extends Command
{
    protected $signature = 'backup:cleanup-stale
        {--force : Delete without confirmation}';

    protected $description = 'Remove "stale" tables from an unfinished restore';

    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if (!in_array($driver, ['mysql', 'mariadb'])) {
            $this->info("Stale table cleanup is only supported for MySQL/MariaDB.");
            return Command::SUCCESS;
        }

        $pdo = DB::getPdo();
        $allTables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        
        $staleTables = array_values(array_filter($allTables, fn($t) => str_starts_with($t, '_restore_backup_')));

        if (empty($staleTables)) {
            $this->info('No stale tables from an unfinished restore found.');
            return Command::SUCCESS;
        }

        $this->warn('Found stale tables from an unfinished restore:');
        foreach ($staleTables as $table) {
            $this->line("  - {$table}");
        }

        $this->newLine();
        $this->warn('These tables will be deleted!');

        if (!$this->option('force') && !$this->confirm('Proceed?')) {
            $this->info('Operation canceled.');
            return Command::SUCCESS;
        }

        $deleted = 0;
        $errors = [];

        foreach ($staleTables as $table) {
            try {
                $quotedTable = str_replace('`', '``', $table);
                $pdo->exec("DROP TABLE IF EXISTS `{$quotedTable}`");
                $deleted++;
                $this->line("  ✓ Deleted: {$table}");
            } catch (\Throwable $e) {
                $errors[] = $table;
                $this->error("  ✗ Error deleting {$table}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Tables deleted: {$deleted}");

        if (!empty($errors)) {
            $this->warn("Failed to delete " . count($errors) . " table(s).");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
