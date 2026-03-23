<?php

namespace Tests\Feature;

use Tests\TestCase;
use Licorice19\Backup\Services\LargeBackupService;
use Licorice19\Backup\Services\StreamingZipWriter;
use Licorice19\Backup\Services\StreamingZipReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;


test('service can be instantiated', function () {
    $service = new LargeBackupService();
    
    expect($service)->toBeInstanceOf(LargeBackupService::class);
});

test('can set progress callback', function () {
    $service = new LargeBackupService();
    
    $progressCalled = false;
    
    $result = $service->onProgress(function ($stage, $current, $total, $percent) use (&$progressCalled) {
        $progressCalled = true;
    });
    
    expect($result)->toBe($service);
});

test('can set completion callback', function () {
    $service = new LargeBackupService();
    
    $result = $service->onComplete(function ($data) {});
    
    expect($result)->toBe($service);
});

test('can set error callback', function () {
    $service = new LargeBackupService();
    
    $result = $service->onError(function ($error) {});
    
    expect($result)->toBe($service);
});

test('can verify backup integrity', function () {
    Storage::fake('local');
    
    $tempDir = sys_get_temp_dir();
    $zipName = 'verify_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql', 'CREATE TABLE test (id INT);');
    $zip->addFromString('manifest.json', json_encode(['driver' => 'mysql']));
    $zip->close();
    
    $service = new LargeBackupService();
    $service->verifyBackup($zipPath);
    
    expect(true)->toBeTrue();
    
    unlink($zipPath);
});


test('verify backup throws for missing database', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'invalid_test_' . uniqid() . '.zip';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFromString('manifest.json', json_encode([]));
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    
    $service = new LargeBackupService();
    
    $this->expectException(\Licorice19\Backup\Exceptions\BackupException::class);
    $service->verifyBackup($zipPath);
    
    unlink($zipPath);
});

test('can verify checksum', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'checksum_test_' . uniqid() . '.zip';
    $content = 'Test content for checksum';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFromString('test.txt', $content);
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    $expectedSha256 = hash_file('sha256', $zipPath);
    
    $service = new LargeBackupService();
    $service->verifyChecksum($zipPath, ['sha256' => $expectedSha256]);
    
    expect(true)->toBeTrue();
    
    unlink($zipPath);
});

test('verify checksum throws on mismatch', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'mismatch_test_' . uniqid() . '.zip';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFromString('test.txt', 'content');
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    
    $service = new LargeBackupService();
    
    $this->expectException(\Licorice19\Backup\Exceptions\BackupException::class);
    $service->verifyChecksum($zipPath, ['sha256' => 'wrong_checksum']);
    
    unlink($zipPath);
});

test('can get memory usage', function () {
    $memory = LargeBackupService::getMemoryUsage();
    
    expect($memory)->toHaveKey('used');
    expect($memory)->toHaveKey('peak');
    expect($memory)->toHaveKey('limit');
});

test('temp directory is created', function () {
    $tempDir = sys_get_temp_dir() . '/test_temp_' . uniqid();
    config(['backup.temp_path' => $tempDir]);
    
    $service = new LargeBackupService();
    
    expect(is_dir($tempDir))->toBeTrue();
    
    // Cleanup
    rmdir($tempDir);
});

test('can handle backup path creation', function () {
    Storage::fake('test_disk');
    
    $service = new LargeBackupService();
    
    expect($service)->toBeInstanceOf(LargeBackupService::class);
});

test('manifest is valid json', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'manifest_test_' . uniqid() . '.zip';
    
    $service = new LargeBackupService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('createManifest');
    $method->setAccessible(true);
    
    $manifest = $method->invoke($service);
    
    expect($manifest)->toHaveKey('created_at');
    expect($manifest)->toHaveKey('database');
    expect($manifest)->toHaveKey('backup_type');
    expect($manifest['backup_type'])->toBe('large');
    
    // Should be valid JSON
    $json = json_encode($manifest);
    expect(json_decode($json))->not->toBeNull();
});

test('can build mysql dsn', function () {
    $service = new LargeBackupService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('buildDsn');
    $method->setAccessible(true);
    
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'testdb',
    ];
    
    $dsn = $method->invoke($service, $config);
    
    expect($dsn)->toBe('mysql:host=127.0.0.1;port=3306;dbname=testdb');
});

test('can get dump settings', function () {
    $service = new LargeBackupService();
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('getDumpSettings');
    $method->setAccessible(true);
    
    $settings = $method->invoke($service);
    
    expect($settings)->toHaveKey('single-transaction');
    expect($settings)->toHaveKey('add-drop-table');
    expect($settings)->toHaveKey('extended-insert');
});
