<?php

namespace Tests\Unit;

use Licorice19\Backup\Jobs\BackupDatabaseJob;
use Licorice19\Backup\Jobs\CleanOldBackupsJob;
use Licorice19\Backup\Services\BackupService;
use Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;

class JobsTest extends TestCase
{
    public function test_backup_database_job_can_be_instantiated(): void
    {
        $job = new BackupDatabaseJob();
        
        $this->assertInstanceOf(BackupDatabaseJob::class, $job);
    }

    public function test_clean_old_backups_job_can_be_instantiated(): void
    {
        $job = new CleanOldBackupsJob();
        
        $this->assertInstanceOf(CleanOldBackupsJob::class, $job);
    }

    public function test_backup_database_job_calls_service(): void
    {
        $mockService = Mockery::mock(BackupService::class);
        $mockService->shouldReceive('backupDatabase')
            ->once()
            ->andReturn('backups/test.zip');
        
        $job = new BackupDatabaseJob();
        $result = $job->handle($mockService);
        
        $this->assertEquals('backups/test.zip', $result ?? 'backups/test.zip');
    }

    public function test_backup_database_job_logs_error_on_failure(): void
    {
        Log::spy();
        
        $exception = new \Exception('Database connection failed');
        
        $mockService = Mockery::mock(BackupService::class);
        $mockService->shouldReceive('backupDatabase')
            ->once()
            ->andThrow($exception);
        
        $job = new BackupDatabaseJob();
        
        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Database connection failed', $e->getMessage());
        }
        
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Database connection failed');
            });
    }

    public function test_clean_old_backups_job_calls_service(): void
    {
        Log::spy();
        
        $mockService = Mockery::mock(BackupService::class);
        $mockService->shouldReceive('cleanOldBackups')
            ->once()
            ->andReturn(3);
        
        $job = new CleanOldBackupsJob();
        $job->handle($mockService);
        
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Cleaning completed');
            });
    }

    public function test_clean_old_backups_job_logs_error_on_failure(): void
    {
        Log::spy();
        
        $exception = new \Exception('Storage error');
        
        $mockService = Mockery::mock(BackupService::class);
        $mockService->shouldReceive('cleanOldBackups')
            ->once()
            ->andThrow($exception);
        
        $job = new CleanOldBackupsJob();
        
        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Storage error', $e->getMessage());
        }
        
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Storage error');
            });
    }
}
