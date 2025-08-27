<?php

namespace App\Commands;

use App\Models\Document;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\Panther\DomCrawler\Crawler;

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
        
        $this->info("Using ChromeDriver at: {$driverPath}");

        // Kill any existing ChromeDriver processes to free up the port
        $this->killExistingChromeDrivers();

        // Create Chrome client in headless mode (no visible browser window)
        $client = PantherClient::createChromeClient($driverPath, [
            '--headless',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1920,1080',
            '--remote-debugging-port=0', // Use random available port
        ]);

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
}
