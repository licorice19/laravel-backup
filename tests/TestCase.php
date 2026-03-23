<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Licorice19\Backup\BackupServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('backup.disk', 'local');
        $app['config']->set('backup.path', 'backups');
        $app['config']->set('backup.days_to_keep', 7);
        $app['config']->set('backup.max_backups', 10);
        $app['config']->set('backup.include_files', false);
        $app['config']->set('backup.directories', []);
        $app['config']->set('backup.exclude', ['.git', '.env', 'node_modules', 'vendor']);
        $app['config']->set('backup.transactional_restore', true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}
