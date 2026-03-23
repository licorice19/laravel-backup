<?php

return [
    /*

    |--------------------------------------------------------------------------
    | Backup Storage Disk
    |--------------------------------------------------------------------------

    |
    | The name of the Laravel Filesystem disk where backups will be stored.
    | Supported: local, public, s3, ftp, and any custom disks.

    |
    */
    'disk' => env('BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Backup Storage Path
    |--------------------------------------------------------------------------

    |
    | The relative path within the selected disk to store backup files.

    |
    */
    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Retention Days
    |--------------------------------------------------------------------------

    |
    | Backups older than the specified number of days will be deleted 

    | during the cleanup process.
    |
    */
    'days_to_keep' => env('BACKUP_DAYS_TO_KEEP', 7),

    /*

    |--------------------------------------------------------------------------
    | Maximum Number of Backups
    |--------------------------------------------------------------------------

    |
    | The maximum number of backup files to keep. When the limit is 
    | exceeded, the oldest backups will be removed.

    |
    */
    'max_backups' => env('BACKUP_MAX_BACKUPS', 10),

    /*

    |--------------------------------------------------------------------------
    | Include Files in Backup
    |--------------------------------------------------------------------------

    |
    | If set to true, files from the specified directories will be included 

    | in the backup.
    |
    */
    'include_files' => env('BACKUP_INCLUDE_FILES', false),

    /*

    |--------------------------------------------------------------------------
    | Directories to Backup
    |--------------------------------------------------------------------------

    |
    | List of directories that will be included in the file backup.
    |
    */
    'directories' => [
        storage_path('app/public'),
    ],

    /*

    |--------------------------------------------------------------------------
    | Backup Exclusions
    |--------------------------------------------------------------------------

    |
    | Files and directories that will be excluded from the backup.
    |
    */
    'exclude' => [
        '.git',
        '.env',
        'node_modules',
        'vendor',
    ],

    /*

    |--------------------------------------------------------------------------
    | Transactional Restore
    |--------------------------------------------------------------------------

    |
    | If true: tables are renamed to _restore_backup_*, then the dump is imported.
    | On failure, an automatic rollback to the original tables occurs.

    | Only supported for MySQL/MariaDB.
    |
    */
    'transactional_restore' => env('BACKUP_TRANSACTIONAL_RESTORE', true),

    /*
    |--------------------------------------------------------------------------
    | Large Database Settings (5GB+)
    |--------------------------------------------------------------------------
    |
    | Settings optimized for shared hosting with strict memory limits.
    | These settings are used by LargeBackupService.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Temporary Path
    |--------------------------------------------------------------------------
    |
    | Directory for temporary files during backup/restore operations.
    | Should have enough space for temporary ZIP and SQL files.
    | Default: storage/app/backup-temp
    |
    */
    'temp_path' => env('BACKUP_TEMP_PATH', storage_path('app/backup-temp')),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Number of SQL statements to execute in each batch during restore.
    | Smaller values = less memory usage but slower restore.
    | Recommended: 500-2000 for shared hosting.
    |
    */
    'chunk_size' => env('BACKUP_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time allowed for a single backup/restore operation.
    | Set higher for very large databases.
    | Default: 3600 (1 hour)
    |
    */
    'max_execution_time' => env('BACKUP_MAX_EXECUTION_TIME', 3600),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit Strategy
    |--------------------------------------------------------------------------
    |
    | Strategy for handling memory limits:
    | - 'strict': Use streaming for all operations, never load full files
    | - 'moderate': Use streaming only for files > certain threshold
    | - 'none': Let PHP handle memory (not recommended for shared hosting)
    |
    */
    'memory_strategy' => env('BACKUP_MEMORY_STRATEGY', 'strict'),

    /*
    |--------------------------------------------------------------------------
    | Streaming Threshold (bytes)
    |--------------------------------------------------------------------------
    |
    | For 'moderate' memory strategy, only use streaming when file size
    | exceeds this threshold.
    | Default: 50MB
    |
    */
    'streaming_threshold' => env('BACKUP_STREAMING_THRESHOLD', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Large Database Detection
    |--------------------------------------------------------------------------
    |
    | Automatically use LargeBackupService when database exceeds this size.
    | Set to 0 to always use standard backup.
    | Default: 500MB (automatically use large backup for >500MB databases)
    |
    */
    'large_db_threshold' => env('BACKUP_LARGE_DB_THRESHOLD', 500 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | ZIP Compression Level
    |--------------------------------------------------------------------------
    |
    | Compression level for ZIP archives (0-9).
    | 0 = no compression (fastest, largest files)
    | 9 = maximum compression (slowest, smallest files)
    | For large databases on shared hosting: 1-3 recommended.
    |
    */
    'compression_level' => env('BACKUP_COMPRESSION_LEVEL', 1),
];
