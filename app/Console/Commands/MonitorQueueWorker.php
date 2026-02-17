<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class MonitorQueueWorker extends Command
{
    protected $signature = 'queue:monitor';
    protected $description = 'Start and monitor the queue worker safely';

    public function handle()
    {
        $lock = Cache::lock('queue_worker_running', 15 * 60);

        if (! $lock->get()) {
            Log::info('Queue worker already running');
            return self::SUCCESS;
        }

        Log::info('Queue worker started by monitor');

        try {
            Artisan::call('queue:work', [
                '--sleep' => 3,
                '--tries' => 3,
                '--timeout' => 90,
            ]);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    
    }
}
