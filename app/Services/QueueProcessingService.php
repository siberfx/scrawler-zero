<?php

namespace App\Services;

use App\Models\QueueManagement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueueProcessingService
{
    /**
     * Process a single queue item
     */
    public function processQueueItem(QueueManagement $item): void
    {
        try {
            // Update status to processing
            $item->update([
                'status' => 'processing',
                'last_crawled_at' => now(),
            ]);

            // Make the HTTP request to the URL
            $response = Http::timeout(30)->get($item->url);
            
            // Calculate next crawl time
            $nextCrawlAt = $this->calculateNextCrawlTime($item->frequency, $item->custom_minutes);
            
            // Prepare crawl stats
            $stats = [
                'status_code' => $response->status(),
                'response_time' => $response->transferStats ? $response->transferStats->getTransferTime() : 0,
                'crawled_at' => now()->toDateTimeString(),
                'success' => $response->successful(),
            ];

            // Update queue item with results
            $item->update([
                'status' => $response->successful() ? 'completed' : 'failed',
                'last_crawled_at' => now(),
                'next_crawl_at' => $nextCrawlAt,
                'crawl_stats' => array_merge($item->crawl_stats ?? [], [
                    now()->timestamp => $stats
                ]),
                'notes' => $response->successful() 
                    ? ($item->notes ?: null) 
                    : ($item->notes ? $item->notes . "\n\n" : '') . "Crawl failed with status: " . $response->status(),
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error processing queue item {$item->id}: " . $e->getMessage());
            
            $item->update([
                'status' => 'failed',
                'notes' => ($item->notes ? $item->notes . "\n\n" : '') . "Error: " . $e->getMessage(),
            ]);
            
            throw $e; // Re-throw to be handled by the caller
        }
    }

    /**
     * Calculate the next crawl time based on frequency
     */
    public function calculateNextCrawlTime(string $frequency, ?int $customMinutes = null): \Illuminate\Support\Carbon
    {
        $now = now();
        
        return match($frequency) {
            'every_minute' => $now->addMinute(),
            'hourly' => $now->addHour(),
            'daily' => $now->addDay(),
            'weekly' => $now->addWeek(),
            'monthly' => $now->addMonth(),
            'custom' => $now->addMinutes($customMinutes ?? 60),
            default => $now->addHour(),
        };
    }
}
