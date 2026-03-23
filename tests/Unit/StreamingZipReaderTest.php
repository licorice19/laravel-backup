<?php

namespace Tests\Unit;

use Tests\TestCase;
use Licorice19\Backup\Services\StreamingZipReader;
use Licorice19\Backup\Services\StreamingZipWriter;
use RuntimeException;

function createTestZip(string $path, array $files): bool
{
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    
    return $zip->close();
}

test('can open existing zip', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'reader_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['test.txt' => 'Hello World']);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    expect($reader->count())->toBe(1);
    expect($reader->listEntries())->toContain('test.txt');
    
    $reader->close();
    unlink($zipPath);
});

test('can read entry content', function () {
    $tempDir = sys_get_temp_dir();
    $content = 'Test content for reading';
    $zipName = 'content_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['readme.txt' => $content]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    expect($reader->getEntryContent('readme.txt'))->toBe($content);
    
    $reader->close();
    unlink($zipPath);
});

test('can check entry existence', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'exists_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['existing.txt' => 'content']);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    expect($reader->hasEntry('existing.txt'))->toBeTrue();
    expect($reader->hasEntry('nonexistent.txt'))->toBeFalse();
    
    $reader->close();
    unlink($zipPath);
});

test('can get entry info', function () {
    $tempDir = sys_get_temp_dir();
    $content = 'Some test content';
    $zipName = 'info_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['info.txt' => $content]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    $info = $reader->getEntryInfo('info.txt');
    
    expect($info)->not->toBeNull();
    expect($info['uncompressed_size'])->toBe(strlen($content));
    
    $reader->close();
    unlink($zipPath);
});

test('can extract to file', function () {
    $tempDir = sys_get_temp_dir();
    $content = 'Content to extract';
    $zipName = 'extract_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['extract.txt' => $content]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    $extractPath = $tempDir . '/extracted_' . uniqid() . '.txt';
    $reader->extractToFile('extract.txt', $extractPath);
    
    expect(file_get_contents($extractPath))->toBe($content);
    
    $reader->close();
    unlink($zipPath);
    unlink($extractPath);
});

test('can extract to directory', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'dir_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, [
        'dir/file1.txt' => 'content1',
        'dir/file2.txt' => 'content2',
        'dir/sub/file3.txt' => 'content3',
    ]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    $extractDir = $tempDir . '/extract_' . uniqid();
    mkdir($extractDir, 0755, true);
    
    $count = $reader->extractToDirectory($extractDir);
    
    expect($count)->toBe(3);
    expect(file_get_contents($extractDir . '/dir/file1.txt'))->toBe('content1');
    expect(file_get_contents($extractDir . '/dir/file2.txt'))->toBe('content2');
    expect(file_get_contents($extractDir . '/dir/sub/file3.txt'))->toBe('content3');
    
    $reader->close();
    unlink($zipPath);
    
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($extractDir);
});

test('can check integrity', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'integrity_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, ['valid.txt' => 'Valid content']);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    $errors = $reader->checkIntegrity();
    
    expect($errors)->toHaveCount(0);
    
    $reader->close();
    unlink($zipPath);
});

test('throws exception for nonexistent file', function () {
    $this->expectException(RuntimeException::class);
    
    $reader = new StreamingZipReader('/nonexistent/path.zip');
    $reader->open();
});

test('can handle unicode filenames', function () {
    $tempDir = sys_get_temp_dir();
    $content = 'Unicode content';
    $zipName = 'unicode_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, [
        '日本語.txt' => $content,
        'Ελληνικά.txt' => $content,
        'Русский.txt' => $content,
    ]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    expect($reader->count())->toBe(3);
    
    $reader->close();
    unlink($zipPath);
});

test('can read multiple entries', function () {
    $tempDir = sys_get_temp_dir();
    $zipName = 'multi_test_' . uniqid() . '.zip';
    $zipPath = $tempDir . '/' . $zipName;
    
    createTestZip($zipPath, [
        'file1.txt' => 'Content 1',
        'file2.txt' => 'Content 2',
        'file3.txt' => 'Content 3',
    ]);
    
    $reader = new StreamingZipReader($zipPath);
    $reader->open();
    
    expect($reader->getEntryContent('file1.txt'))->toBe('Content 1');
    expect($reader->getEntryContent('file2.txt'))->toBe('Content 2');
    expect($reader->getEntryContent('file3.txt'))->toBe('Content 3');
    
    $reader->close();
    unlink($zipPath);
});
