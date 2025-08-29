<?php

namespace App\Commands;

use App\Models\Document;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Illuminate\Console\Scheduling\Schedule;

class CrawlOpenOverheidCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openoverheid:crawl 
                            {--organisation=mnre1058 : The organisation identifier} 
                            {--filter-id=min : The filter-id} 
                            {--page=1 : The page number to start from}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl open.overheid.nl search results and store them in the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting open.overheid.nl crawler with Panther...');

        $organisation = $this->option('organisation');
        $filterId = $this->option('filter-id');
        $page = (int) $this->option('page');

        $baseUrl = 'https://open.overheid.nl/zoekresultaten';
        $processedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;

        // Determine the correct ChromeDriver executable based on the operating system
        if (PHP_OS_FAMILY === 'Windows') {
            $driverPath = base_path('drivers/chromedriver.exe');
        } else {
            // Try common ChromeDriver locations on Linux
            $possiblePaths = [
                '/usr/local/bin/chromedriver',
                '/usr/bin/chromedriver',
                '/home/chrome/chromedriver',
                base_path('drivers/chromedriver')
            ];
            
            $driverPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $driverPath = $path;
                    break;
                }
            }
        }

        // Check if the driver exists
        if (! $driverPath || ! file_exists($driverPath)) {
            $this->error("ChromeDriver not found. Searched locations:");
            if (PHP_OS_FAMILY === 'Windows') {
                $this->error("- {$driverPath}");
            } else {
                foreach ($possiblePaths as $path) {
                    $this->error("- {$path}");
                }
            }
            $this->info('Please install ChromeDriver:');
            $this->info('Ubuntu: sudo apt-get install chromium-chromedriver');
            $this->info('Or download from: https://chromedriver.chromium.org/');

            return 1;
        }
        
        // Check if the driver is executable
        if (!is_executable($driverPath)) {
            $this->error("ChromeDriver found at {$driverPath} but is not executable.");
            $this->info("Run: sudo chmod +x {$driverPath}");
            return 1;
        }
        
        // Test ChromeDriver execution
        $testCommand = escapeshellarg($driverPath) . ' --version 2>&1';
        exec($testCommand, $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->error("ChromeDriver execution test failed:");
            $this->error("Command: {$testCommand}");
            $this->error("Return code: {$returnCode}");
            $this->error("Output: " . implode("\n", $output));
            
            // Check for common issues
            $this->info("\nTroubleshooting steps:");
            $this->info("1. Verify Chrome/Chromium is installed: which google-chrome || which chromium-browser");
            $this->info("2. Check ChromeDriver compatibility with Chrome version");
            $this->info("3. Install missing dependencies: sudo apt-get install libnss3 libgconf-2-4 libxss1 libappindicator1 libindicator7");
            
            return 1;
        }
        
        $this->info("Using ChromeDriver at: {$driverPath}");
        $this->info("ChromeDriver version: " . trim(implode(' ', $output)));
        
        // Check Chrome installation and version
        exec('google-chrome --version 2>/dev/null || chromium-browser --version 2>/dev/null', $chromeOutput, $chromeReturnCode);
        if ($chromeReturnCode === 0 && !empty($chromeOutput)) {
            $this->info("Chrome version: " . trim($chromeOutput[0]));
        } else {
            $this->warn("Chrome/Chromium not found or not accessible. This may cause issues.");
        }

        // Kill any existing ChromeDriver processes to free up the port
        $this->killExistingChromeDrivers();

        // Create Chrome client in headless mode (no visible browser window)
        $tempDir = '/tmp/chrome-' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            // Try with chromium-browser binary explicitly
            $client = PantherClient::createChromeClient($driverPath, [
                '--headless',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor',
                '--disable-extensions',
                '--disable-plugins',
                '--disable-default-apps',
                '--disable-sync',
                '--no-first-run',
                '--no-default-browser-check',
                '--window-size=1920,1080',
                '--user-data-dir=' . $tempDir,
                '--disable-software-rasterizer',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
                '--disable-field-trial-config',
                '--disable-ipc-flooding-protection',
            ], [], 'chromium-browser');
        } catch (\Exception $e) {
            $this->error("Failed with chromium-browser: " . $e->getMessage());
            $this->info("Trying with google-chrome...");
            
            try {
                $client = PantherClient::createChromeClient($driverPath, [
                    '--headless',
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-gpu',
                    '--disable-web-security',
                    '--window-size=1920,1080',
                    '--user-data-dir=' . $tempDir,
                ], [], 'google-chrome');
            } catch (\Exception $fallbackError) {
                $this->error("Failed with google-chrome: " . $fallbackError->getMessage());
                $this->info("Trying minimal configuration...");
                
                // Final fallback: Minimal configuration without specifying browser
                $client = PantherClient::createChromeClient($driverPath, [
                    '--headless',
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-gpu',
                    '--remote-debugging-port=9222',
                ]);
            }
        }

        while (true) {
            $url = "{$baseUrl}?filter-id--organisatie={$filterId}&organisatie={$organisation}&page={$page}";
            $this->info("Crawling page: {$page} -> {$url}");

            try {
                $crawler = $client->request('GET', $url);

                $client->waitFor('div.result--list', 30); // Wait up to 30 seconds for the results to appear

                $documents = $this->parseDocuments($crawler, $organisation);

                if (empty($documents)) {
                    $this->info('No more documents found on this page. Ending crawl.');
                    break;
                }

                $this->info('Found '.count($documents).' documents on this page.');

                foreach ($documents as $docData) {
                    Document::withoutSyncingToSearch(function () use ($docData, &$createdCount, &$updatedCount) {
                        $document = Document::where('source_url', $docData['source_url'])->first();
                        if ($document) {
                            $document->update($docData);
                            $updatedCount++;
                        } else {
                            Document::create($docData);
                            $createdCount++;
                        }
                    });
                    $processedCount++;
                }

                // Check if there is a 'next' page link
                $nextLink = $crawler->filter('.pagination .next a');
                if ($nextLink->count() === 0) {
                    $this->info('No next page link found. Ending crawl.');
                    break;
                }

                $page++;
                sleep(1); // Be a good citizen

            } catch (\Exception $e) {
                $this->error("An error occurred: {$e->getMessage()}");
                Log::error('CrawlOpenOverheidCommand Error', ['exception' => $e]);
                break;
            }
        }

        $client->quit();
        
        // Clean up temp directory
        if (isset($tempDir) && is_dir($tempDir)) {
            exec("rm -rf " . escapeshellarg($tempDir));
        }
        
        $this->info("Crawling finished. Processed: {$processedCount} (Created: {$createdCount}, Updated: {$updatedCount})");

        return 0;
    }

    /**
     * Parse the HTML content to extract documents.
     */
    protected function parseDocuments(Crawler $crawler, string $organisation): array
    {
        $documents = [];

        // Target the specific structure: div.result--list li items
        $nodes = $crawler->filter('div.result--list li');

        foreach ($nodes as $node) {
            try {
                $titleNode = $node->findElement(WebDriverBy::cssSelector('h2.result--title a'));
                $title = trim($titleNode->getText());
                $source_url = 'https://open.overheid.nl'.trim($titleNode->getAttribute('href'));

                $documents[] = [
                    'title' => $title,
                    'source_url' => $source_url,
                    'organisation_suffix' => $organisation, // Store the organisation for filtering
                    'is_processed' => false, // Mark as unprocessed for later detailed fetching
                    'fetch_timestamp' => now(),
                ];
            } catch (NoSuchElementException $e) {
                // This can happen if a result item is an ad or has a different structure.
                $this->warn('Skipping a result item that could not be parsed.');

                continue;
            }
        }

        return $documents;
    }

    /**
     * Kill any existing ChromeDriver processes to free up ports.
     */
    protected function killExistingChromeDrivers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Kill ChromeDriver processes on Windows
            exec('taskkill /F /IM chromedriver.exe 2>nul', $output, $returnCode);
        } else {
            // Kill ChromeDriver processes on Unix/Linux
            exec('pkill -f chromedriver 2>/dev/null', $output, $returnCode);
        }

        // Give processes time to terminate
        sleep(1);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class)
            ->everySixHours()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(base_path('logs/openoverheid-crawl.log'));
    }
}
