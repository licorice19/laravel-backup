<?php

namespace Licorice19\Backup\Jobs;

use Licorice19\Backup\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestoreDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    public function handle(BackupService $backupService): void
    {
        try {
            $backupService->restore($this->filename);
            Log::info("Restore completed from: {$this->filename}");
        } catch (\Exception $e) {
            Log::error('Restore Error: ' . $e->getMessage());
            throw $e;
        }
    }
}