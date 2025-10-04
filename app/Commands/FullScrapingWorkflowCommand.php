<?php

namespace App\Commands;

use Illuminate\Console\Command;

class FullScrapingWorkflowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:workflow 
                            {--collect-pages=5 : Number of pages to collect URLs from}
                            {--process-limit=50 : Limit for processing URLs}
                            {--skip-collect : Skip URL collection step}
                            {--skip-process : Skip URL processing step}
                            {--skip-convert : Skip converting to documents step}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run complete scraping workflow: collect URLs → process URLs → convert to documents';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $collectPages = (int) $this->option('collect-pages');
        $processLimit = (int) $this->option('process-limit');
        $skipCollect = $this->option('skip-collect');
        $skipProcess = $this->option('skip-process');
        $skipConvert = $this->option('skip-convert');
        
        $this->info('🚀 Starting complete scraping workflow...');
        $this->newLine();
        
        // Step 1: Collect URLs using Python
        if (!$skipCollect) {
            $this->info('📋 Step 1: Collecting URLs from search pages...');
            $exitCode = $this->call('python:scraper', ['action' => 'collect']);
            
            if ($exitCode !== 0) {
                $this->error('❌ URL collection failed');
                return $exitCode;
            }
            
            $this->info('✅ URL collection completed');
            $this->newLine();
        }
        
        // Step 2: Process URLs using Python (scrape detailed data)
        if (!$skipProcess) {
            $this->info('🔍 Step 2: Processing URLs and scraping detailed data...');
            $args = ['action' => 'process'];
            if ($processLimit > 0) {
                $args['--limit'] = $processLimit;
            }
            
            $exitCode = $this->call('python:scraper', $args);
            
            if ($exitCode !== 0) {
                $this->error('❌ URL processing failed');
                return $exitCode;
            }
            
            $this->info('✅ URL processing completed');
            $this->newLine();
        }
        
        // Step 3: Convert processed URLs to Document records
        if (!$skipConvert) {
            $this->info('📄 Step 3: Converting processed URLs to Document records...');
            $exitCode = $this->call('process:python-urls', ['--limit' => $processLimit]);
            
            if ($exitCode !== 0) {
                $this->error('❌ Document conversion failed');
                return $exitCode;
            }
            
            $this->info('✅ Document conversion completed');
            $this->newLine();
        }
        
        $this->info('🎉 Complete scraping workflow finished successfully!');
        $this->info('💡 You can now run: php scrawler python:scraper monitor');
        
        return 0;
    }
}
