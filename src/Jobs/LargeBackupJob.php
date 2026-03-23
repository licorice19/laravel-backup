<?php

namespace Licorice19\Backup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Licorice19\Backup\Services\LargeBackupService;

/**
 * Job for creating large database backups via queue.
 * 
 * This job is designed for shared hosting environments where:
 * - Memory limits are strict (32-128MB)
 * - Execution time may be limited
 * - Background processing is needed for large databases
 */
class LargeBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting large backup job');

        try {
            $backupService = new LargeBackupService();

            $backupService->onProgress(function ($stage, $current, $total, $percent) {
                Log::info("Large backup progress: {$stage} - {$percent}%");
            });

            $result = $backupService->backupDatabase();

            Log::info('Large backup job completed', [
                'filename' => $result['filename'],
                'path' => $result['path'],
                'checksum' => $result['checksum'],
            ]);

        } catch (\Throwable $e) {
            Log::error('Large backup job failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Large backup job failed permanently', [
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['backup', 'large', 'database'];
    }
}
