<?php

namespace Tests\Unit;

use Tests\TestCase;
use Licorice19\Backup\Services\StreamingZipWriter;
use RuntimeException;

test('can create zip writer', function () {
    $tempDir = sys_get_temp_dir();
    $writer = new StreamingZipWriter($tempDir);
    
    expect($writer)->toBeInstanceOf(StreamingZipWriter::class);
});

test('can add file to zip', function () {
    $tempDir = sys_get_temp_dir();
    
    $testFile = $tempDir . '/test_content_' . uniqid() . '.txt';
    file_put_contents($testFile, 'Hello, World! This is test content.');
    
    $zipName = 'test_' . uniqid() . '.zip';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFile($testFile, 'test.txt');
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    expect(file_exists($zipPath))->toBeTrue();
    
    unlink($testFile);
    unlink($zipPath);
});

test('can add string content to zip', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'test_string_' . uniqid() . '.zip';
    $content = 'Test content with special chars';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFromString('test.txt', $content);
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    expect(file_exists($zipPath))->toBeTrue();
    expect($writer->count())->toBe(1);
    
    unlink($zipPath);
});

test('can add multiple files to zip', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'test_multi_' . uniqid() . '.zip';
    
    // Create multiple test files
    $files = [];
    for ($i = 1; $i <= 5; $i++) {
        $file = $tempDir . "/test_{$i}_" . uniqid() . '.txt';
        file_put_contents($file, "Content of file {$i}");
        $files[] = $file;
    }
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    
    foreach ($files as $i => $file) {
        $writer->addFile($file, "file_{$i}.txt");
    }
    
    $writer->close();
    
    $zip = new \ZipArchive();
    $zip->open($tempDir . '/' . $zipName);
    expect($zip->numFiles)->toBe(5);
    $zip->close();
    
    foreach ($files as $file) {
        unlink($file);
    }
    unlink($tempDir . '/' . $zipName);
});

test('can add large content to zip', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'test_large_' . uniqid() . '.zip';
    
    $largeContent = str_repeat('x', 1024 * 1024);
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFromString('large_file.bin', $largeContent);
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    expect(file_exists($zipPath))->toBeTrue();
    
    expect(filesize($zipPath))->toBeGreaterThan(0);
    expect($writer->count())->toBe(1);
    
    unlink($zipPath);
});

test('throws exception for invalid directory', function () {
    $this->expectException(\ErrorException::class);
    
    new StreamingZipWriter('/nonexistent/path/that/does/not/exist');
});

test('can add file with subdirectory path', function () {
    $tempDir = sys_get_temp_dir();
    $testFile = $tempDir . '/test_subdir_' . uniqid() . '.txt';
    file_put_contents($testFile, 'Content in subdirectory');
    
    $zipName = 'test_subdir_' . uniqid() . '.zip';
    
    $writer = new StreamingZipWriter($tempDir);
    $writer->open($zipName);
    $writer->addFile($testFile, 'subdir/nested/file.txt');
    $writer->close();
    
    $zipPath = $tempDir . '/' . $zipName;
    
    expect(file_exists($zipPath))->toBeTrue();
    expect($writer->count())->toBe(1);
    
    unlink($testFile);
    unlink($zipPath);
});
