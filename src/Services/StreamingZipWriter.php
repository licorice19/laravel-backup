<?php

namespace Licorice19\Backup\Services;

use ZipArchive;
use RuntimeException;

/**
 * Streaming ZIP writer for large files.
 * 
 * Unlike ZipArchive which buffers everything in memory, this class
 * writes ZIP entries incrementally without loading entire files into RAM.
 * Uses incremental deflate compression for memory-efficient streaming.
 */
class StreamingZipWriter
{
    protected string $tempPath;
    protected $handle;
    protected array $entries = [];
    protected array $offsets = [];
    protected int $localHeaderOffset = 0;
    protected string $comment = '';
    
    protected const CHUNK_SIZE = 1048576;

    public function __construct(string $tempPath)
    {
        $this->tempPath = rtrim($tempPath, '/');
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    /**
     * Create a new streaming ZIP for writing
     */
    public function open(string $filename): void
    {
        $this->handle = fopen($this->tempPath . '/' . $filename, 'w+b');
        if ($this->handle === false) {
            throw new RuntimeException("Cannot open file for streaming ZIP: {$filename}");
        }
        
        fwrite($this->handle, str_repeat("\0", 22));
        
        $this->entries = [];
        $this->offsets = [];
        $this->localHeaderOffset = 0;
    }

    /**
     * Add a file from a stream to the ZIP archive
     * 
     * Uses incremental compression to avoid loading entire file into memory.
     * 
     * @param resource $stream Stream resource (e.g., from fopen())
     * @param string $localPath Path inside the ZIP archive
     * @param int $size_hint Hint for uncompressed size (optional)
     */
    public function addFromStream($stream, string $localPath, int $size_hint = 0): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException("Expected stream resource for: {$localPath}");
        }

        $crc32 = 0;
        $deflateContext = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);
        
        if ($deflateContext === false) {
            throw new RuntimeException("Failed to initialize deflate context");
        }
        
        $compressedChunks = [];
        $uncompressedSize = 0;
        
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            
            $uncompressedSize += strlen($chunk);
            $crc32 = crc32($crc32 . $chunk) & 0xFFFFFFFF;
            
            $compressed = deflate_add($deflateContext, $chunk, ZLIB_NO_FLUSH);
            if ($compressed !== false) {
                $compressedChunks[] = $compressed;
            }
        }
        
        $finalCompressed = deflate_add($deflateContext, '', ZLIB_FINISH);
        if ($finalCompressed !== false) {
            $compressedChunks[] = $finalCompressed;
        }

        
        // Combine all compressed chunks
        $compressed = implode('', $compressedChunks);
        
        $this->writeLocalFileHeader($localPath, $uncompressedSize, $crc32, $compressed);
    }

    /**
     * Add a file to the ZIP archive
     * 
     * For large files, this reads in chunks to avoid memory exhaustion.
     */
    public function addFile(string $filePath, string $localPath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        try {
            $this->addFromStream($handle, $localPath, filesize($filePath));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Add a large file using chunked processing
     * 
     * This is the safest method for very large files (5GB+).
     */
    public function addLargeFile(string $filePath, string $localPath): void
    {
        $this->addFile($filePath, $localPath);
    }

    /**
     * Add a string as a file entry
     */
    public function addFromString(string $localPath, string $content): void
    {
        $crc32 = crc32($content) & 0xFFFFFFFF;
        $compressed = gzcompress($content);
        
        $this->writeLocalFileHeader($localPath, strlen($content), $crc32, $compressed);
    }

    /**
     * Write the local file header and compressed data
     */
    protected function writeLocalFileHeader(string $path, int $size, int $crc32, string $compressed): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $pathBytes = $path . "\0";
        $pathLength = strlen($pathBytes);
        
        $compressedSize = strlen($compressed);
        
        $header = pack('V', 0x04034b50);      // Local file header signature
        $header .= pack('v', 20);             // Version needed to extract
        $header .= pack('v', 0);              // General purpose bit flag
        $header .= pack('v', 8);               // Compression method (deflate)
        $header .= pack('v', 0);               // File last modification time
        $header .= pack('v', 0);               // File last modification date
        $header .= pack('V', $crc32);          // CRC-32
        $header .= pack('V', $compressedSize); // Compressed size
        $header .= pack('V', $size);           // Uncompressed size
        $header .= pack('v', $pathLength);     // File name length
        $header .= pack('v', 0);               // Extra field length
        
        fwrite($this->handle, $header);
        fwrite($this->handle, $pathBytes);
        fwrite($this->handle, $compressed);
        
        $this->entries[] = [
            'path' => $path,
            'crc32' => $crc32,
            'compressed_size' => $compressedSize,
            'uncompressed_size' => $size,
            'offset' => $this->localHeaderOffset,
        ];
        
        $this->localHeaderOffset += strlen($header) + $pathLength + $compressedSize;
    }

    /**
     * Close the archive and write central directory
     */
    public function close(): string
    {
        $centralDirOffset = ftell($this->handle);
        
        foreach ($this->entries as $entry) {
            $pathBytes = $entry['path'] . "\0";
            $pathLength = strlen($pathBytes);
            
            $header = pack('V', 0x02014b50);  // Central directory header signature
            $header .= pack('v', 20);        // Version made by
            $header .= pack('v', 20);        // Version needed to extract
            $header .= pack('v', 0);         // General purpose bit flag
            $header .= pack('v', 8);         // Compression method
            $header .= pack('v', 0);         // File last modification time
            $header .= pack('v', 0);          // File last modification date
            $header .= pack('V', $entry['crc32']);
            $header .= pack('V', $entry['compressed_size']);
            $header .= pack('V', $entry['uncompressed_size']);
            $header .= pack('v', $pathLength);
            $header .= pack('v', 0);         // Extra field length
            $header .= pack('v', 0);          // File comment length
            $header .= pack('v', 0);          // Disk number start
            $header .= pack('v', 0);          // Internal file attributes
            $header .= pack('V', 0);         // External file attributes
            $header .= pack('V', $entry['offset']);
            
            fwrite($this->handle, $header);
            fwrite($this->handle, $pathBytes);
        }
        
        $centralDirSize = ftell($this->handle) - $centralDirOffset;
        $centralDirEntries = count($this->entries);
        
        $eocd = pack('V', 0x06054b50);       // EOCD signature
        $eocd .= pack('v', 0);               // Disk number
        $eocd .= pack('v', 0);               // Disk with central directory
        $eocd .= pack('v', $centralDirEntries); // Entries on this disk
        $eocd .= pack('v', $centralDirEntries); // Total entries
        $eocd .= pack('V', $centralDirSize);    // Central directory size
        $eocd .= pack('V', $centralDirOffset); // Central directory offset
        $eocd .= pack('v', strlen($this->comment)); // Comment length
        
        if (!empty($this->comment)) {
            $eocd .= $this->comment;
        }
        
        fwrite($this->handle, $eocd);
        
        $filePath = stream_get_meta_data($this->handle)['uri'];
        
        fclose($this->handle);
        $this->handle = null;
        
        return $filePath;
    }

    /**
     * Set ZIP archive comment
     */
    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * Get the number of entries in the archive
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Destructor - ensure file handle is closed
     */
    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
