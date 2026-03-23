<?php

namespace Licorice19\Backup\Jobs;

use Licorice19\Backup\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanOldBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BackupService $backupService): void
    {
        try {
            $count = $backupService->cleanOldBackups();
            Log::info("Cleaning completed, backups deleted: {$count}");
        } catch (\Exception $e) {
            Log::error('Error clearing backups: ' . $e->getMessage());
            throw $e;
        }
    }
}