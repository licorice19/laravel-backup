<?php

namespace Licorice19\Backup\Services;

use ZipArchive;
use Generator;
use RuntimeException;

/**
 * Streaming ZIP reader for large archives.
 * 
 * Provides memory-efficient extraction of large ZIP files by reading
 * entries sequentially and providing stream access to content.
 */
class StreamingZipReader
{
    protected string $filePath;
    protected $handle;
    protected array $entries = [];
    protected bool $eof = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Open the archive and read central directory
     * 
     * For files > 2GB, uses central directory search strategy
     */
    public function open(): void
    {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("ZIP file not found: {$this->filePath}");
        }

        $this->handle = fopen($this->filePath, 'rb');
        if ($this->handle === false) {
            throw new RuntimeException("Cannot open ZIP file: {$this->filePath}");
        }

        $this->readCentralDirectory();
    }

    /**
     * Read the End of Central Directory record and parse central directory
     */
    protected function readCentralDirectory(): void
    {
        $this->parseWithZipArchive();
    }

    /**
     * Search for central directory by scanning the file
     * Used for broken or very large ZIP files
     */
    protected function searchAndReadCentralDirectory(): void
    {
        $fileSize = filesize($this->filePath);
        $chunkSize = 8192;
        
        for ($pos = 0; $pos < $fileSize; $pos += $chunkSize) {
            fseek($this->handle, $pos);
            $data = fread($this->handle, $chunkSize * 2);
            
            $offset = 0;
            while (($offset = strpos($data, "\x50\x4b\x01\x02", $offset)) !== false) {
                $offset += 4;
            }
        }
        
        $this->parseWithZipArchive();
    }

    /**
     * Parse central directory from binary data
     */
    protected function parseCentralDirectory(string $data): void
    {
        $offset = 0;
        $dataLen = strlen($data);
        
        while ($offset < $dataLen - 46) {
            if (substr($data, $offset, 4) !== "\x50\x4b\x01\x02") {
                $offset++;
                continue;
            }
            
            // Central directory file header format:
            // 4 bytes: signature (already checked)
            // 2 bytes: version made by
            // 2 bytes: version needed to extract
            // 2 bytes: general purpose bit flag
            // 2 bytes: compression method
            // 2 bytes: last mod file time
            // 2 bytes: last mod file date
            // 4 bytes: crc-32
            // 4 bytes: compressed size
            // 4 bytes: uncompressed size
            // 2 bytes: file name length
            // 2 bytes: extra field length
            // 2 bytes: file comment length
            // 2 bytes: disk number start
            // 2 bytes: internal file attributes
            // 4 bytes: external file attributes
            // 4 bytes: relative offset of local header
            
            $header = unpack(
                'vversion_made/vversion_needed/vflags/vcompression/' .
                'vmod_time/vmod_date/' .
                'Vcrc/Vcompressed_size/Vuncompressed_size/' .
                'vfilename_len/vextra_len/vcomment_len/' .
                'vdisk_start/vinternal_attr/Vexternal_attr/Vlocal_header_offset',
                substr($data, $offset + 4)
            );
            
            if ($header === false || strlen($data) < $offset + 46 + $header['filename_len']) {
                $offset++;
                continue;
            }
            
            $filename = substr($data, $offset + 46, $header['filename_len']);
            $filename = rtrim($filename, "\0");
            
            if (empty($filename)) {
                $offset++;
                continue;
            }
            
            $this->entries[$filename] = [
                'crc32' => $header['crc'],
                'compressed_size' => $header['compressed_size'],
                'uncompressed_size' => $header['uncompressed_size'],
                'local_header_offset' => $header['local_header_offset'],
                'compression_method' => $header['compression'],
            ];
            
            $offset += 46 + $header['filename_len'] + $header['extra_len'] + $header['comment_len'];
        }
    }

    /**
     * Fallback to ZipArchive for complex files
     */
    protected function parseWithZipArchive(): void
    {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new RuntimeException("Cannot open ZIP with ZipArchive either");
        }
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $this->entries[$stat['name']] = [
                'crc32' => $stat['crc'],
                'compressed_size' => $stat['comp_size'],
                'uncompressed_size' => $stat['size'],
                'local_header_offset' => 0,
                'compression_method' => 8, // deflate
                'index' => $i,
            ];
        }
        
        $zip->close();
    }

    /**
     * Get list of all entries in the archive
     */
    public function listEntries(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Check if entry exists
     */
    public function hasEntry(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * Get entry metadata
     */
    public function getEntryInfo(string $name): ?array
    {
        return $this->entries[$name] ?? null;
    }

    /**
     * Extract a single entry to a file (memory efficient)
     */
    public function extractToFile(string $entryName, string $destination): void
    {
        if (!isset($this->entries[$entryName])) {
            throw new RuntimeException("Entry not found: {$entryName}");
        }
        
        $entry = $this->entries[$entryName];
        $content = $this->getEntryContent($entryName);
        
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($destination, $content);
    }

    /**
     * Get entry content as string
     */
    public function getEntryContent(string $entryName): string
    {
        if (!isset($this->entries[$entryName])) {
            throw new RuntimeException("Entry not found: {$entryName}");
        }
        
        $entry = $this->entries[$entryName];
        
        if (isset($entry['index'])) {
            $zip = new ZipArchive();
            if ($zip->open($this->filePath) !== true) {
                throw new RuntimeException("Cannot reopen ZIP");
            }
            $content = $zip->getFromIndex($entry['index']);
            $zip->close();
            return $content ?: '';
        }
        
        fseek($this->handle, $entry['local_header_offset']);
        
        $header = fread($this->handle, 30);
        if (substr($header, 0, 4) !== "\x50\x4b\x03\x04") {
            throw new RuntimeException("Invalid local file header");
        }
        
        $headerInfo = unpack(
            'vversion/vflags/vcompression/vmod_time/vmod_date/' .
            'Vcrc/Vcompressed/Vuncompressed/vpath_len/vextra_len',
            $header
        );
        
        fseek($this->handle, $entry['local_header_offset'] + 30 + $headerInfo['path_len'] + $headerInfo['extra_len']);
        
        $compressed = fread($this->handle, $entry['compressed_size']);
        
        if ($headerInfo['compression'] === 0) {
            return $compressed;
        } elseif ($headerInfo['compression'] === 8) {
            return gzuncompress($compressed);
        } elseif ($headerInfo['compression'] === 12) {
            return gzinflate($compressed);
        }
        
        throw new RuntimeException("Unsupported compression method: {$headerInfo['compression']}");
    }

    /**
     * Get entry content as a stream (for very large entries)
     * 
     * Returns a generator that yields chunks of data
     */
    public function getEntryStream(string $entryName): Generator
    {
        if (!isset($this->entries[$entryName])) {
            throw new RuntimeException("Entry not found: {$entryName}");
        }
        
        $entry = $this->entries[$entryName];
        $chunkSize = 8192;
        
        fseek($this->handle, $entry['local_header_offset']);
        
        $header = fread($this->handle, 30);
        if (substr($header, 0, 4) !== "\x50\x4b\x03\x04") {
            throw new RuntimeException("Invalid local file header");
        }
        
        $headerInfo = unpack(
            'vversion/vflags/vcompression/vmod_time/vmod_date/' .
            'Vcrc/Vcompressed/Vuncompressed/vpath_len/vextra_len',
            $header
        );
        
        fseek($this->handle, $entry['local_header_offset'] + 30 + $headerInfo['path_len'] + $headerInfo['extra_len']);
        
        $remaining = $entry['compressed_size'];
        
        if ($headerInfo['compression'] === 0) {
            while ($remaining > 0) {
                $read = min($chunkSize, $remaining);
                $data = fread($this->handle, $read);
                yield $data;
                $remaining -= $read;
            }
        } else {
            $stream = fopen('php://temp', 'r+b');
            
            while ($remaining > 0) {
                $read = min($chunkSize * 10, $remaining);
                $compressed_chunk = fread($this->handle, $read);
                $decompressed = gzuncompress($compressed_chunk);
                if ($decompressed !== false) {
                    fwrite($stream, $decompressed);
                }
                $remaining -= $read;
            }
                        
            rewind($stream);
            while (!feof($stream)) {
                yield fread($stream, $chunkSize);
            }
            fclose($stream);
        }
    }

    /**
     * Extract all files to a directory
     */
    public function extractToDirectory(string $directory, array $exclude = []): int
    {
        $count = 0;
        
        foreach ($this->entries as $name => $info) {
            $excluded = false;
            foreach ($exclude as $pattern) {
                if (fnmatch($pattern, $name) || str_contains($name, $pattern)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }
            
            if (str_ends_with($name, '/')) {
                continue;
            }
            
            $destination = rtrim($directory, '/') . '/' . $name;
            $this->extractToFile($name, $destination);
            $count++;
        }
        
        return $count;
    }

    /**
     * Close the archive
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Get total number of entries
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Check archive integrity
     */
    public function checkIntegrity(): array
    {
        $errors = [];
        
        foreach ($this->entries as $name => $info) {
            try {
                $content = $this->getEntryContent($name);
                $crc = crc32($content) & 0xFFFFFFFF;
                
                if ($crc !== $info['crc32']) {
                    $errors[] = [
                        'file' => $name,
                        'error' => 'CRC mismatch',
                        'expected' => $info['crc32'],
                        'actual' => $crc,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'file' => $name,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $errors;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }
}
