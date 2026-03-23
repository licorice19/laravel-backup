<?php

namespace Licorice19\Backup;

use Illuminate\Support\ServiceProvider;
use Licorice19\Backup\Services\BackupService;
use Licorice19\Backup\Console\Commands\BackupRun;
use Licorice19\Backup\Console\Commands\BackupList;
use Licorice19\Backup\Console\Commands\BackupRestore;
use Licorice19\Backup\Console\Commands\BackupClean;

class BackupServiceProvider extends ServiceProvider
{
    /**
     * Register services provided by the package.
     */
    public function register(): void
    {
        $this->app->singleton(BackupService::class, function () {
            return new BackupService();
        });

        $this->app->alias(BackupService::class, 'backup');

        $this->mergeConfigFrom(
            __DIR__ . '/config/backup.php',
            'backup'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/backup.php' => config_path('backup.php'),
        ], 'backup-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupRun::class,
                BackupList::class,
                BackupRestore::class,
                BackupClean::class,
            ]);
        }
    }
}