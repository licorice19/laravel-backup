<?php

namespace Licorice19\Backup\Services;

use PDO;
use Generator;
use RuntimeException;

/**
 * Chunked SQL Executor for large database restores.
 * 
 * Reads SQL statements from a stream/file and executes them in chunks
 * to avoid loading the entire dump into memory.
 */
class ChunkedSqlExecutor
{
    protected PDO $pdo;
    protected int $chunkSize;
    protected int $maxExecutionTime;
    protected int $statementCount = 0;
    protected int $executedStatements = 0;
    protected float $startTime;
    protected array $callbacks = [];

    public function __construct(
        PDO $pdo,
        int $chunkSize = 1000,
        int $maxExecutionTime = 300
    ) {
        $this->pdo = $pdo;
        $this->chunkSize = $chunkSize;
        $this->maxExecutionTime = $maxExecutionTime;
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Set callback for progress updates
     */
    public function onProgress(callable $callback): self
    {
        $this->callbacks['progress'] = $callback;
        return $this;
    }

    /**
     * Execute SQL from a file in chunks
     */
    public function executeFromFile(string $sqlFile): array
    {
        $this->startTime = microtime(true);
        $this->statementCount = $this->countStatements($sqlFile);
        $this->executedStatements = 0;
        
        $handle = fopen($sqlFile, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open SQL file: {$sqlFile}");
        }
        
        try {
            return $this->executeFromStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Execute SQL from a stream in chunks (memory efficient)
     */
    public function executeFromStream($stream): array
    {
        $this->startTime = microtime(true);
        
        $batch = [];
        $batchCount = 0;
        $totalBytes = 0;
        
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }
            
            $totalBytes += strlen($line);
            
            $trimmed = trim($line);
            if ($this->shouldSkipLine($trimmed)) {
                continue;
            }
            
            $batch[] = $line;
            
            if (count($batch) >= $this->chunkSize || $this->shouldFlush($batch)) {
                $this->executeBatch($batch);
                $batchCount++;
                $batch = [];
                
                $this->checkTimeout();
                
                gc_collect_cycles();
            }
        }
        
        if (!empty($batch)) {
            $this->executeBatch($batch);
            $batchCount++;
        }
        
        return [
            'statements' => $this->executedStatements,
            'batches' => $batchCount,
            'bytes_processed' => $totalBytes,
            'execution_time' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Execute SQL from a string in chunks
     */
    public function executeFromString(string $sql): array
    {
        $this->startTime = microtime(true);
        $this->statementCount = substr_count($sql, ';');
        $this->executedStatements = 0;
        
        $statements = $this->parseStatements($sql);
        
        $batches = array_chunk($statements, $this->chunkSize);
        $batchCount = 0;
        
        foreach ($batches as $batch) {
            $this->executeBatch($batch);
            $batchCount++;
            $this->checkTimeout();
            gc_collect_cycles();
        }
        
        return [
            'statements' => $this->executedStatements,
            'batches' => $batchCount,
            'bytes_processed' => strlen($sql),
            'execution_time' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Execute SQL from a stream generator (most memory efficient)
     */
    public function executeFromGenerator(Generator $sqlGenerator): array
    {
        $this->startTime = microtime(true);
        
        $batch = [];
        $batchCount = 0;
        $totalBytes = 0;
        
        foreach ($sqlGenerator as $line) {
            $totalBytes += strlen($line);
            
            $trimmed = trim($line);
            if ($this->shouldSkipLine($trimmed)) {
                continue;
            }
            
            $batch[] = $line;
            
            if (count($batch) >= $this->chunkSize) {
                $this->executeBatch($batch);
                $batchCount++;
                $batch = [];
                $this->checkTimeout();
            }
        }
        
        if (!empty($batch)) {
            $this->executeBatch($batch);
            $batchCount++;
        }
        
        return [
            'statements' => $this->executedStatements,
            'batches' => $batchCount,
            'bytes_processed' => $totalBytes,
            'execution_time' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Generate SQL statements from a file line by line
     */
    public static function readFileAsGenerator(string $sqlFile): Generator
    {
        $handle = fopen($sqlFile, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open SQL file: {$sqlFile}");
        }
        
        try {
            while (!feof($handle)) {
                yield fgets($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Generate SQL statements from a ZIP entry
     */
    public function readZipEntryAsGenerator(StreamingZipReader $reader, string $entryName): Generator
    {
        $entry = $reader->getEntryInfo($entryName);
        
        if ($entry && $entry['uncompressed_size'] > 100 * 1024 * 1024) {
            foreach ($reader->getEntryStream($entryName) as $chunk) {
                yield $chunk;
            }
        } else {
            $content = $reader->getEntryContent($entryName);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                yield $line . "\n";
            }
        }
    }

    /**
     * Execute a batch of SQL statements in a transaction
     */
    protected function executeBatch(array $statements): void
    {
        $this->pdo->beginTransaction();
        
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if (empty($trimmed) || $trimmed === ';') {
                    continue;
                }
                
                if (str_ends_with($trimmed, ';')) {
                    $trimmed = substr($trimmed, 0, -1);
                }
                
                if (!empty(trim($trimmed))) {
                    $this->pdo->exec($trimmed);
                    $this->executedStatements++;
                }
            }
            
            $this->pdo->commit();
            
            if (isset($this->callbacks['progress'])) {
                $progress = $this->statementCount > 0 
                    ? ($this->executedStatements / $this->statementCount) * 100 
                    : 0;
                ($this->callbacks['progress'])($this->executedStatements, $this->statementCount, $progress);
            }
            
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException(
                "Failed to execute batch at statement #{$this->executedStatements}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Check if a line should be skipped (comment or empty)
     */
    protected function shouldSkipLine(string $trimmed): bool
    {
        if ($trimmed === '') {
            return true;
        }
        
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            return true;
        }
        
        if ($trimmed === '/*' || $trimmed === '*/' || str_starts_with($trimmed, '/*!')) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if batch should be flushed based on content
     */
    protected function shouldFlush(array $batch): bool
    {
        $lastLine = trim(end($batch));
        
        if (str_ends_with($lastLine, ';')) {
            return true;
        }
        
        foreach ($batch as $line) {
            if (stripos($line, 'DELIMITER') !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Parse SQL string into individual statements
     */
    protected function parseStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $delimiter = ';';
        
        $len = strlen($sql);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            
            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $char . $sql[++$i];
                } else {
                    $inString = false;
                }
            } elseif (!$inString && $char === $delimiter) {
                $stmt = trim($current);
                if (!empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, '#')) {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            } elseif (!$inString && $char === "\n" && strlen($current) > 10) {
                $trimmed = trim($current);
                if (stripos($trimmed, 'DELIMITER') === 0) {
                    $parts = explode('DELIMITER', $trimmed);
                    if (isset($parts[1])) {
                        $delimiter = trim(explode(' ', $parts[1])[0]);
                    }
                }
            }
            
            $current .= $char;
        }
        
        $stmt = trim($current);
        if (!empty($stmt) && !str_starts_with($stmt, '--') && !str_starts_with($stmt, '#')) {
            $statements[] = $stmt;
        }
        
        return $statements;
    }

    /**
     * Count total statements in a file (approximate)
     */
    protected function countStatements(string $sqlFile): int
    {
        $count = 0;
        $handle = fopen($sqlFile, 'r');
        
        while (!feof($handle)) {
            $line = fgets($handle);
            $count += substr_count($line, ';');
        }
        
        fclose($handle);
        return $count;
    }

    /**
     * Check if max execution time is exceeded
     */
    protected function checkTimeout(): void
    {
        $elapsed = microtime(true) - $this->startTime;
        if ($elapsed > $this->maxExecutionTime) {
            throw new RuntimeException(
                "Maximum execution time ({$this->maxExecutionTime}s) exceeded. " .
                "Executed {$this->executedStatements} statements in {$elapsed}s."
            );
        }
        
        $maxPhpTime = ini_get('max_execution_time');
        if ($maxPhpTime > 0 && $elapsed > $maxPhpTime - 5) {
        }
    }

    /**
     * Get execution statistics
     */
    public function getStats(): array
    {
        return [
            'executed_statements' => $this->executedStatements,
            'total_statements' => $this->statementCount,
            'elapsed_time' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Enable/disable foreign key checks for batch operations
     */
    public function setForeignKeyChecks(bool $enabled): void
    {
        $this->pdo->exec($enabled 
            ? 'SET FOREIGN_KEY_CHECKS = 1' 
            : 'SET FOREIGN_KEY_CHECKS = 0'
        );
    }

    /**
     * Configure SQL mode for compatibility
     */
    public function setSqlMode(string $mode = ''): void
    {
        $this->pdo->exec('SET SQL_MODE = ' . ($mode ? "'{$mode}'" : '""'));
    }
}
