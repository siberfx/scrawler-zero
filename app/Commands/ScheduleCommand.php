<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ScheduleCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'schedule:run';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run the scheduled commands';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Running scheduled commands...');
        
        // The schedule method below defines what commands should run
        // Laravel Zero will automatically handle the scheduling
        $this->info('Schedule configuration loaded successfully.');
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // Crawl organizations daily at 2:00 AM
        $schedule->command('organizations:crawl')
            ->dailyAt('02:00')
            ->withoutOverlapping(1440) // 24 hours timeout
            ->runInBackground()
            ->appendOutputTo(base_path('logs/organizations-crawl.log'))
            ->onOneServer(); // Ensure only one server runs this

        // Crawl OpenOverheid documents every 6 hours
        $schedule->command('openoverheid:crawl')
            ->everySixHours()
            ->withoutOverlapping(360) // 6 hours timeout
            ->runInBackground()
            ->appendOutputTo(base_path('logs/openoverheid-crawl.log'))
            ->onOneServer();

        // Process organization details daily at 3:00 AM (after organizations crawl)
        $schedule->command('organizations:process-details --limit=500')
            ->dailyAt('03:00')
            ->withoutOverlapping(1440) // 24 hours timeout
            ->runInBackground()
            ->appendOutputTo(base_path('logs/organizations-process.log'))
            ->onOneServer();

        // Process documents every 2 hours
        $schedule->command('documents:process --limit=50')
            ->everyTwoHours()
            ->withoutOverlapping(120) // 2 hours timeout
            ->runInBackground()
            ->appendOutputTo(base_path('logs/documents-process.log'))
            ->onOneServer();

        // Fetch PID data daily at 4:00 AM (after organizations are processed)
        $schedule->command('pid:fetch --pid=nl.mnre1058 --save-to-db --save-relational')
            ->dailyAt('04:00')
            ->withoutOverlapping(1440) // 24 hours timeout
            ->runInBackground()
            ->appendOutputTo(base_path('logs/pid-fetch.log'))
            ->onOneServer();

        // Cleanup old logs weekly
        $schedule->call(function () {
            $logPath = base_path('logs');
            if (!is_dir($logPath)) {
                mkdir($logPath, 0755, true);
            }
            $files = glob($logPath . '/*.log');
            foreach ($files as $file) {
                if (filemtime($file) < strtotime('-30 days')) {
                    unlink($file);
                }
            }
        })->weekly()->sundays()->at('01:00');
    }
}
