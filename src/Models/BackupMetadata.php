<?php

namespace Licorice19\Backup\Models;

use Illuminate\Database\Eloquent\Model;

class BackupMetadata extends Model
{
    protected $table = 'backup_metadata';
    
    public $timestamps = false;
    
    protected $fillable = [
        'filename',
        'sha256',
        'size',
        'driver',
        'has_database',
        'has_files',
        'manifest',
        'created_at',
    ];
    
    protected $casts = [
        'has_database' => 'boolean',
        'has_files' => 'boolean',
        'manifest' => 'array',
        'created_at' => 'datetime',
    ];
    
    /**
     * Get backup by filename
     */
    public static function getByFilename(string $filename): ?self
    {
        return static::where('filename', $filename)->first();
    }
    
    /**
     * Check if backup exists in metadata
     */
    public static function exists(string $filename): bool
    {
        return static::where('filename', $filename)->exists();
    }
    
    /**
     * Delete metadata by filename
     */
    public static function deleteByFilename(string $filename): bool
    {
        return static::where('filename', $filename)->delete() > 0;
    }
}
