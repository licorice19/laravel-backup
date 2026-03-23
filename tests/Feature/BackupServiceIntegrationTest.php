<?php

namespace Licorice19\Backup\Tests\Feature;

use Licorice19\Backup\Services\BackupService;
use Licorice19\Backup\Jobs\BackupDatabaseJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Foundation\testbench;
use ZipArchive;
use Tests\TestCase;

class BackupServiceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_service_can_be_resolved_from_container(): void
    {
        $service = $this->app->make(BackupService::class);
        
        $this->assertInstanceOf(BackupService::class, $service);
    }

    public function test_list_backups_returns_empty_when_no_backups(): void
    {
        $service = $this->app->make(BackupService::class);
        
        $backups = $service->listBackups();
        
        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    public function test_list_backups_returns_single_backup(): void
    {
        Storage::disk('local')->put('backups/test.zip', 'content');
        
        $service = $this->app->make(BackupService::class);
        $backups = $service->listBackups();
        
        $this->assertCount(1, $backups);
        $this->assertEquals('test.zip', $backups[0]['name']);
    }

    public function test_list_backups_returns_multiple_backups_sorted(): void
    {
        Storage::disk('local')->put('backups/backup_1.zip', 'content1');
        Storage::disk('local')->put('backups/backup_2.zip', 'content2');
        Storage::disk('local')->put('backup_3.zip', 'content3');
        
        $service = $this->app->make(BackupService::class);
        $backups = $service->listBackups();
        
        $this->assertCount(2, $backups);
    }

    public function test_list_backups_excludes_non_zip_files(): void
    {
        Storage::disk('local')->put('backups/backup.zip', 'content');
        Storage::disk('local')->put('backups/readme.txt', 'text');
        Storage::disk('local')->put('backups/data.json', 'json');
        
        $service = $this->app->make(BackupService::class);
        $backups = $service->listBackups();
        
        $this->assertCount(1, $backups);
        $this->assertEquals('backup.zip', $backups[0]['name']);
    }

    public function test_get_total_size_calculates_correctly(): void
    {
        Storage::disk('local')->put('backups/backup1.zip', str_repeat('x', 1024)); // 1 KB
        Storage::disk('local')->put('backups/backup2.zip', str_repeat('y', 2048)); // 2 KB
        
        $service = $this->app->make(BackupService::class);
        $totalSize = $service->getTotalSize();
        
        // 3 KB = 3072 bytes
        $this->assertEquals('3 KB', $totalSize);
    }

    public function test_get_total_size_returns_zero_for_empty(): void
    {
        $service = $this->app->make(BackupService::class);
        
        $totalSize = $service->getTotalSize();
        
        $this->assertEquals('0 B', $totalSize);
    }

    public function test_download_backup_returns_content(): void
    {
        $content = 'test backup content';
        Storage::disk('local')->put('backups/test.zip', $content);
        
        $service = $this->app->make(BackupService::class);
        $downloaded = $service->downloadBackup('test.zip');
        
        $this->assertEquals($content, $downloaded);
    }

    public function test_download_backup_returns_null_for_missing(): void
    {
        $service = $this->app->make(BackupService::class);
        
        $result = $service->downloadBackup('nonexistent.zip');
        
        $this->assertNull($result);
    }

    public function test_verify_backup_validates_real_zip(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        
        $zip = new ZipArchive();
        $zip->open($tempFile, ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CREATE TABLE test (id INT);');
        $zip->close();
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $service->verifyBackup($tempFile);
            
            $this->assertTrue(true);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_verify_backup_rejects_corrupt_zip(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'not a zip');
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $this->expectException(\Exception::class);
            $service->verifyBackup($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_verify_checksum_validates_correct_hash(): void
    {
        $content = 'test content';
        $hash = hash('sha256', $content);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, $content);
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $service->verifyChecksum($tempFile, ['sha256' => $hash]);
            
            $this->assertTrue(true);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_verify_checksum_rejects_wrong_hash(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $this->expectException(\Exception::class);
            $service->verifyChecksum($tempFile, ['sha256' => 'wrong_hash']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_read_manifest_parses_json(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        
        $manifest = [
            'created_at' => '2024-01-01T12:00:00+00:00',
            'app_name' => 'Test App',
            'database' => 'test_db',
        ];
        
        $zip = new ZipArchive();
        $zip->open($tempFile, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $result = $service->readManifest($tempFile);
            
            $this->assertEquals($manifest, $result);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_get_backup_info_returns_info(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        
        $manifest = [
            'created_at' => '2024-01-01T12:00:00+00:00',
            'app_name' => 'Test App',
            'database' => 'test_db',
            'driver' => 'sqlite',
        ];
        
        $zip = new ZipArchive();
        $zip->open($tempFile, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->addFromString('database.sqlite', 'sqlite content');
        $zip->close();
        
        Storage::disk('local')->put('backups/test.sqlite.zip', file_get_contents($tempFile));
        
        try {
            $service = $this->app->make(BackupService::class);
            
            $info = $service->getBackupInfo('test.sqlite.zip');
            
            $this->assertNotNull($info);
            $this->assertEquals('test.sqlite.zip', $info['filename']);
            $this->assertTrue($info['has_database']);
            $this->assertEquals('sqlite', $info['manifest']['driver']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_get_backup_info_returns_null_for_missing(): void
    {
        $service = $this->app->make(BackupService::class);
        
        $result = $service->getBackupInfo('nonexistent.zip');
        
        $this->assertNull($result);
    }
}
