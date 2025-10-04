<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PythonScraperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'python:scraper 
                            {action : Action to perform (collect|process|monitor)}
                            {--limit= : Limit for processing URLs}
                            {--timeout=300 : Timeout in seconds for the process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Python MongoDB scraper through Laravel';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $limit = $this->option('limit');
        $timeout = (int) $this->option('timeout');

        // Validate action
        $validActions = ['collect', 'process', 'monitor'];
        if (! in_array($action, $validActions)) {
            $this->error('Invalid action. Valid actions: '.implode(', ', $validActions));

            return 1;
        }

        // Build Python command
        $pythonScript = base_path('bin/python-scrawler/mongodb_url_scraper.py');
        $monitorScript = base_path('bin/python-scrawler/mongodb_monitor.py');
        
        // Detect Python executable (python3 on Linux, python on Windows)
        $pythonExecutable = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
        
        // Check if we're in a virtual environment
        $venvPython = base_path('bin/python-scrawler/venv/bin/python');
        if (file_exists($venvPython)) {
            $pythonExecutable = $venvPython;
        }

        if ($action === 'monitor') {
            $command = [$pythonExecutable, $monitorScript];
        } else {
            $command = [$pythonExecutable, $pythonScript, $action];
            if ($limit && $action === 'process') {
                $command[] = $limit;
            }
        }

        $this->info('Running Python scraper: '.implode(' ', $command));
        $this->info('Working directory: '.base_path('bin/python-scrawler'));

        // Create and run the process
        $process = new Process($command, base_path('bin/python-scrawler'));
        $process->setTimeout($timeout);

        try {
            if ($action === 'monitor') {
                // For monitor, run interactively
                $this->runInteractiveProcess($process);
            } else {
                // For collect/process, show output as it happens
                $this->runStreamingProcess($process);
            }

            $exitCode = $process->getExitCode();

            if ($exitCode === 0) {
                $this->info('✓ Python scraper completed successfully');
            } else {
                $this->error("✗ Python scraper failed with exit code: {$exitCode}");
            }

            return $exitCode;

        } catch (\Exception $e) {
            $this->error('Error running Python scraper: '.$e->getMessage());

            return 1;
        }
    }

    /**
     * Run process with real-time output streaming
     */
    protected function runStreamingProcess(Process $process)
    {
        $process->start();

        while ($process->isRunning()) {
            // Output stdout
            $output = $process->getIncrementalOutput();
            if ($output) {
                $this->line($output);
            }

            // Output stderr
            $errorOutput = $process->getIncrementalErrorOutput();
            if ($errorOutput) {
                $this->error($errorOutput);
            }

            usleep(100000); // Sleep 0.1 seconds
        }

        // Get any remaining output
        $remainingOutput = $process->getIncrementalOutput();
        if ($remainingOutput) {
            $this->line($remainingOutput);
        }

        $remainingError = $process->getIncrementalErrorOutput();
        if ($remainingError) {
            $this->error($remainingError);
        }
    }

    /**
     * Run process interactively (for monitor)
     */
    protected function runInteractiveProcess(Process $process)
    {
        $this->info('Starting monitor... Press Ctrl+C to stop');

        $process->start();

        // Handle Ctrl+C gracefully
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($process) {
                $this->info("\nStopping monitor...");
                $process->stop();
            });
        }

        while ($process->isRunning()) {
            // Output stdout
            $output = $process->getIncrementalOutput();
            if ($output) {
                $this->line($output);
            }

            // Output stderr
            $errorOutput = $process->getIncrementalErrorOutput();
            if ($errorOutput) {
                $this->error($errorOutput);
            }

            // Check for signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(500000); // Sleep 0.5 seconds
        }
    }
}
