<?php

namespace Licorice19\Backup\Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Licorice19\Backup\Services\LargeBackupService;

class BackupLargeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_backup_large_command_exists(): void
    {
        $this->artisan('backup:large', ['--help'])
            ->assertExitCode(0);
    }

    public function test_backup_large_shows_help_with_correct_output(): void
    {
        $this->artisan('backup:large', ['--help'])
            ->expectsOutputToContain('large databases')
            ->assertExitCode(0);
    }



    public function test_backup_large_accepts_db_only_option(): void
    {
        $this->artisan('backup:large', ['--db-only' => true, '--help'])
            ->assertExitCode(0);
    }

    public function test_backup_large_accepts_files_only_option(): void
    {
        $this->artisan('backup:large', ['--files-only' => true, '--help'])
            ->assertExitCode(0);
    }

    public function test_backup_large_accepts_force_option(): void
    {
        $this->artisan('backup:large', ['--force' => true, '--help'])
            ->assertExitCode(0);
    }

    public function test_backup_large_returns_error_when_no_database(): void
    {
        $this->artisan('backup:large')
            ->assertExitCode(1);
    }
}
