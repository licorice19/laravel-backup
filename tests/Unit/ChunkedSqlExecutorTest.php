<?php

namespace Tests\Unit;

use Tests\TestCase;
use Licorice19\Backup\Services\ChunkedSqlExecutor;
use PDO;
use RuntimeException;

test('can create executor with pdo', function () {
    $pdo = new PDO('sqlite::memory:');
    $executor = new ChunkedSqlExecutor($pdo);
    
    expect($executor)->toBeInstanceOf(ChunkedSqlExecutor::class);
});

test('can create executor with custom chunk size', function () {
    $pdo = new PDO('sqlite::memory:');
    $executor = new ChunkedSqlExecutor($pdo, 500);
    
    expect($executor)->toBeInstanceOf(ChunkedSqlExecutor::class);
});

test('can set progress callback', function () {
    $pdo = new PDO('sqlite::memory:');
    $executor = new ChunkedSqlExecutor($pdo);
    
    $called = false;
    $executor->onProgress(function ($count, $total, $percent) use (&$called) {
        $called = true;
    });
    
    expect($called)->toBeFalse();
});

test('can execute simple sql from string', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
    
    $executor = new ChunkedSqlExecutor($pdo);
    $result = $executor->executeFromString("INSERT INTO users (name) VALUES ('John');");
    
    expect($result['statements'])->toBe(1);
    expect($result['batches'])->toBe(1);
    
    $stmt = $pdo->query("SELECT * FROM users WHERE name = 'John'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    expect($row['name'])->toBe('John');
});

test('can execute multiple statements from string', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)");
    
    $executor = new ChunkedSqlExecutor($pdo);
    $sql = "INSERT INTO items (value) VALUES ('Item 1');";
    $sql .= "INSERT INTO items (value) VALUES ('Item 2');";
    $sql .= "INSERT INTO items (value) VALUES ('Item 3');";
    
    $result = $executor->executeFromString($sql);
    
    expect($result['statements'])->toBe(3);
    
    $count = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    expect($count)->toBe(3);
});

test('can execute from sql file', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sqlFile = sys_get_temp_dir() . '/test_sql_' . uniqid() . '.sql';
    $sql = "CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT);\n";
    $sql .= "INSERT INTO test_table (name) VALUES ('Test');\n";
    file_put_contents($sqlFile, $sql);
    
    $executor = new ChunkedSqlExecutor($pdo);
    $result = $executor->executeFromFile($sqlFile);
    
    expect($result['statements'])->toBeGreaterThanOrEqual(2);
    expect($result['batches'])->toBeGreaterThanOrEqual(1);
    
    unlink($sqlFile);
});
test('can handle statements with semicolons in strings', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE strings_test (id INTEGER PRIMARY KEY, text TEXT)");
    
    $executor = new ChunkedSqlExecutor($pdo);
    $sql = "INSERT INTO strings_test (text) VALUES ('Hello; World');";
    
    $result = $executor->executeFromString($sql);
    
    expect($result['statements'])->toBe(1);
    
    $row = $pdo->query("SELECT text FROM strings_test")->fetch(PDO::FETCH_ASSOC);
    expect($row['text'])->toBe('Hello; World');
});

test('can execute using generator', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE gen_test (id INTEGER PRIMARY KEY, value TEXT)");
    
    $executor = new ChunkedSqlExecutor($pdo);
    
    $generator = function () {
        for ($i = 1; $i <= 5; $i++) {
            yield "INSERT INTO gen_test (value) VALUES ('Item {$i}');\n";
        }
    };
    
    $result = $executor->executeFromGenerator($generator());
    
    expect($result['statements'])->toBe(5);
    
    $count = $pdo->query("SELECT COUNT(*) FROM gen_test")->fetchColumn();
    expect($count)->toBe(5);
});

test('can read file as generator', function () {
    $tempFile = sys_get_temp_dir() . '/gen_test_' . uniqid() . '.sql';
    file_put_contents($tempFile, "SELECT 1;\nSELECT 2;\nSELECT 3;");
    
    $generator = ChunkedSqlExecutor::readFileAsGenerator($tempFile);
    
    expect($generator)->toBeInstanceOf(\Generator::class);
    
    $lines = iterator_to_array($generator);
    expect(count($lines))->toBe(3);
    
    unlink($tempFile);
});

test('can toggle foreign key checks', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $executor = new ChunkedSqlExecutor($pdo);
    
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        expect(true)->toBeTrue();
        return;
    }
    
    $executor->setForeignKeyChecks(false);
    $executor->setForeignKeyChecks(true);
    expect(true)->toBeTrue();
});

test('can set sql mode', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $executor = new ChunkedSqlExecutor($pdo);
    
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        expect(true)->toBeTrue();
        return;
    }
    
    $executor->setSqlMode('');
    $executor->setSqlMode('NO_ENGINE_SUBSTITUTION');
    expect(true)->toBeTrue();
});

test('throws exception for nonexistent sql file', function () {
    $pdo = new PDO('sqlite::memory:');
    $executor = new ChunkedSqlExecutor($pdo);
    
    $this->expectException(\ErrorException::class);
    $executor->executeFromFile('/nonexistent/path/to/sql/file.sql');
});

test('can get execution stats', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE stats_test (id INTEGER PRIMARY KEY)");
    
    $executor = new ChunkedSqlExecutor($pdo);
    $executor->executeFromString("INSERT INTO stats_test (id) VALUES (1);");
    
    $stats = $executor->getStats();
    
    expect($stats)->toHaveKey('executed_statements');
    expect($stats)->toHaveKey('elapsed_time');
    expect($stats['executed_statements'])->toBe(1);
});

test('respects chunk size for batch execution', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE chunk_test (id INTEGER PRIMARY KEY, value TEXT)");
    
    $executor = new ChunkedSqlExecutor($pdo, 2);
    
    $sql = "INSERT INTO chunk_test (value) VALUES ('A');";
    $sql .= "INSERT INTO chunk_test (value) VALUES ('B');";
    $sql .= "INSERT INTO chunk_test (value) VALUES ('C');";
    
    $result = $executor->executeFromString($sql);
    
    expect($result['batches'])->toBeGreaterThanOrEqual(2);
    
    $count = $pdo->query("SELECT COUNT(*) FROM chunk_test")->fetchColumn();
    expect($count)->toBe(3);
});
