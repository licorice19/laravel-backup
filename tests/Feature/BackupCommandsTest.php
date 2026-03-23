<?php

namespace Licorice19\Backup\Tests\Feature;

use Licorice19\Backup\Services\BackupService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_backup_run_command_is_registered(): void
    {
        $this->artisan('backup:run', ['--help'])
            ->assertExitCode(0);
    }

    public function test_backup_list_command_is_registered(): void
    {
        $this->artisan('backup:list', ['--help'])
            ->assertExitCode(0);
    }

    public function test_backup_clean_command_is_registered(): void
    {
        $this->artisan('backup:clean', ['--help'])
            ->assertExitCode(0);
    }

    public function test_backup_restore_command_is_registered(): void
    {
        $this->artisan('backup:restore', ['--help'])
            ->assertExitCode(0);
    }

    public function test_backup_cleanup_stale_command_is_registered(): void
    {
        $this->artisan('backup:cleanup-stale', ['--help'])
            ->assertExitCode(0);
    }

    public function test_list_command_shows_no_backups_message(): void
    {
        $this->artisan('backup:list')
            ->expectsOutputToContain('No backups found')
            ->assertExitCode(0);
    }

    public function test_list_command_shows_backup_info(): void
    {
        Storage::disk('local')->put('backups/backup_2024-01-01_120000.zip', 'test content');
        
        $this->artisan('backup:list')
            ->expectsOutputToContain('backup_2024-01-01_120000.zip')
            ->assertExitCode(0);
    }

    public function test_restore_command_validates_nonexistent_file(): void
    {
        $this->artisan('backup:restore', ['filename' => 'nonexistent.zip'])
            ->assertExitCode(1);
    }

    public function test_clean_command_executes_successfully(): void
    {
        $this->artisan('backup:clean')
            ->assertExitCode(0);
    }

    public function test_cleanup_stale_command_executes_when_no_stale_tables(): void
    {
        $this->artisan('backup:cleanup-stale')
            ->assertExitCode(0);
    }

    public function test_backup_run_command_executes_with_mock(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('backupDatabase')
                ->once()
                ->andReturn('backups/test.zip');
        });
        
        $this->artisan('backup:run')
            ->assertExitCode(0);
    }

    public function test_backup_run_command_with_files_option(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('backupDatabase')
                ->once()
                ->andReturn('backups/test.zip');
        });
        
        $this->artisan('backup:run', ['--with-files' => true])
            ->assertExitCode(0);
    }

    public function test_backup_run_command_handles_errors(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('backupDatabase')
                ->once()
                ->andThrow(new \Exception('Database connection failed'));
        });
        
        $this->artisan('backup:run')
            ->assertExitCode(1);
    }
}
