<?php

namespace App\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
                            {--page=1 : The page number to start from}
                            {--method=api : Crawling method (api|http|external)}
                            {--limit=50 : Maximum number of documents to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl open.overheid.nl search results using Chrome-free methods (API discovery, HTTP, external rendering)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $organisation = $this->option('organisation');
        $filterId = $this->option('filter-id');
        $page = (int) $this->option('page');
        $method = $this->option('method');
        $limit = (int) $this->option('limit');

        $this->info('Starting Chrome-free crawler for open.overheid.nl');
        $this->info("Method: {$method} | Organisation: {$organisation} | Filter: {$filterId}");

        // Route to appropriate crawling method
        return match ($method) {
            'api' => $this->crawlWithApi($organisation, $filterId, $page, $limit),
            'http' => $this->crawlWithHttp($organisation, $filterId, $page, $limit),
            'external' => $this->crawlWithExternalRenderer($organisation, $filterId, $page, $limit),
            default => $this->crawlWithApi($organisation, $filterId, $page, $limit),
        };
    }

    /**
     * Crawl using HTTP client (no browser needed)
     */
    protected function crawlWithHttp(string $organisation, string $filterId, int $page, int $limit): int
    {
        $baseUrl = 'https://open.overheid.nl/zoeken';
        $processedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;

        $this->info('Using HTTP client method (no browser automation)...');

        while ($processedCount < $limit) {
            $url = "{$baseUrl}?filter-id--organisatie={$filterId}&pagina={$page}";
            $this->info("Crawling page: {$page} -> {$url}");

            try {
                $response = Http::withOptions([
                    'verify' => false, // Disable SSL verification for development
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ])->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                ])->get($url);

                if (! $response->successful()) {
                    $this->error("HTTP request failed with status: {$response->status()}");
                    break;
                }

                $html = $response->body();

                // Check if this is a React SPA
                if (str_contains($html, 'You need to enable JavaScript') || str_contains($html, 'id="root"')) {
                    $this->error('This is a React SPA that requires JavaScript execution.');
                    $this->info('The HTTP method cannot handle JavaScript-based sites.');
                    $this->info('Recommendation: Use your Python script with Playwright instead.');
                    $this->info('Python command: python mongodb_url_scraper.py collect');

                    return 1;
                }

                $documents = $this->parseHtmlDocuments($html, $organisation);

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

                // Check if there is a 'next' page link in HTML
                if (! str_contains($html, 'class="next"') || ! str_contains($html, 'href=')) {
                    $this->info('No next page link found. Ending crawl.');
                    break;
                }

                $page++;
                sleep(1); // Be a good citizen

            } catch (\Exception $e) {
                $this->error("An error occurred: {$e->getMessage()}");
                Log::error('CrawlOpenOverheidCommand HTTP Error', ['exception' => $e]);
                break;
            }
        }

        $this->info("Crawling finished. Processed: {$processedCount} (Created: {$createdCount}, Updated: {$updatedCount})");

        return 0;
    }

    /**
     * Parse HTML content to extract documents using simple string parsing.
     */
    protected function parseHtmlDocuments(string $html, string $organisation): array
    {
        $documents = [];

        // Use DOMDocument for reliable HTML parsing
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Updated selectors for new HTML structure with dynamic CSS classes
        $resultSelectors = [
            // New structure: ul with id="resultaten_lijst" and li with dynamic classes
            '//ul[@id="resultaten_lijst"]//li[contains(@class, "styles_list_item")]',
            // Fallback: any ul containing li items with links
            '//ul[contains(@class, "styles_list")]//li',
            // Legacy structure
            '//div[contains(@class, "result--list")]//li',
        ];

        foreach ($resultSelectors as $selector) {
            $resultNodes = $xpath->query($selector);

            if ($resultNodes->length > 0) {
                foreach ($resultNodes as $node) {
                    // Try multiple title selectors for new structure
                    $titleSelectors = [
                        // New structure: h5 with dynamic classes containing a link
                        './/h5[contains(@class, "styles_heading")]//a[contains(@class, "styles_link")]',
                        // Alternative: any h5 with a link
                        './/h5//a[@href]',
                        // Legacy structure
                        './/h2[contains(@class, "result--title")]//a',
                        // Fallback: any link with title attribute
                        './/a[@title and @href]',
                    ];

                    foreach ($titleSelectors as $titleSelector) {
                        $titleNodes = $xpath->query($titleSelector, $node);

                        if ($titleNodes->length > 0) {
                            $titleNode = $titleNodes->item(0);
                            $title = trim($titleNode->textContent);
                            $href = $titleNode->getAttribute('href');

                            if ($title && $href) {
                                $source_url = str_starts_with($href, 'http') ? $href : 'https://open.overheid.nl'.$href;

                                // Extract additional metadata from the new structure
                                $metadata = $this->extractListItemMetadata($xpath, $node);

                                $documents[] = array_merge([
                                    'title' => $title,
                                    'source_url' => $source_url,
                                    'organisation_suffix' => $organisation,
                                    'is_processed' => false,
                                    'fetch_timestamp' => now(),
                                ], $metadata);

                                break; // Found title, move to next item
                            }
                        }
                    }
                }
                break; // Found results with this selector, no need to try others
            }
        }

        return $documents;
    }

    /**
     * Extract additional metadata from list items in the new structure
     */
    protected function extractListItemMetadata(\DOMXPath $xpath, \DOMNode $node): array
    {
        $metadata = [];

        try {
            // Extract publication date
            $pubDateNodes = $xpath->query('.//p[@id="Gepubliceerd_op" or contains(text(), "Gepubliceerd op")]', $node);
            if ($pubDateNodes->length > 0) {
                $pubDateText = trim($pubDateNodes->item(0)->textContent);
                if (preg_match('/(\d{2}-\d{2}-\d{4})/', $pubDateText, $matches)) {
                    try {
                        $metadata['publication_date'] = \Carbon\Carbon::createFromFormat('d-m-Y', $matches[1])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore date parsing errors
                    }
                }
            }

            // Extract last modified date
            $modDateNodes = $xpath->query('.//p[@id="Laatst_gewijzigd" or contains(text(), "Laatst gewijzigd")]', $node);
            if ($modDateNodes->length > 0) {
                $modDateText = trim($modDateNodes->item(0)->textContent);
                if (preg_match('/(\d{2}-\d{2}-\d{4})/', $modDateText, $matches)) {
                    try {
                        $metadata['last_modified'] = \Carbon\Carbon::createFromFormat('d-m-Y', $matches[1])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore date parsing errors
                    }
                }
            }

            // Extract file type and size for documents
            $fileTypeNodes = $xpath->query('.//p[@id="Bestands_type" or contains(text(), "PDF") or contains(text(), "DOC")]', $node);
            if ($fileTypeNodes->length > 0) {
                $fileType = trim($fileTypeNodes->item(0)->textContent);
                $metadata['file_type'] = $fileType;
            }

            $fileSizeNodes = $xpath->query('.//p[@id="Bestandsgrootte" or contains(text(), "MB") or contains(text(), "KB")]', $node);
            if ($fileSizeNodes->length > 0) {
                $fileSize = trim($fileSizeNodes->item(0)->textContent);
                $metadata['file_size'] = $fileSize;
            }

        } catch (\Exception $e) {
            // Ignore metadata extraction errors
        }

        return $metadata;
    }

    /**
     * Try to crawl using API endpoints discovered through network interception (similar to Python script)
     */
    protected function crawlWithApi(string $organisation, string $filterId, int $page, int $limit): int
    {
        $this->info('Using network interception approach (similar to Python script)...');

        // Step 1: First try to discover API endpoints by intercepting network requests
        $discoveredApis = $this->discoverApiEndpoints($filterId, $page);

        if (empty($discoveredApis)) {
            $this->warn('No API endpoints discovered through network interception.');
            $this->info('Falling back to common endpoint patterns...');

            return $this->tryCommonApiEndpoints($organisation, $filterId, $page, $limit);
        }

        // Step 2: Use discovered API endpoints
        foreach ($discoveredApis as $apiInfo) {
            $this->info("Using discovered API: {$apiInfo['url']}");

            try {
                $response = Http::withOptions([
                    'verify' => false, // Disable SSL verification for development
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ])->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Referer' => "https://open.overheid.nl/zoeken?filter-id--organisatie={$filterId}&pagina={$page}",
                ])->get($apiInfo['url'], $apiInfo['params'] ?? []);

                if ($response->successful()) {
                    $data = $response->json();
                    if (! empty($data)) {
                        $this->info('✓ Successfully retrieved data from discovered API');

                        // Parse the API response similar to Python script
                        $documents = $this->parseApiResponse($data, $organisation);

                        if (! empty($documents)) {
                            return $this->storeDocuments($documents);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->line("  ✗ API call failed: {$e->getMessage()}");

                // Log more details for debugging
                if (str_contains($e->getMessage(), 'SSL certificate')) {
                    $this->warn('  SSL certificate issue detected. Using verify=false to bypass.');
                } elseif (str_contains($e->getMessage(), 'timeout')) {
                    $this->warn('  Request timeout. The API might be slow or unavailable.');
                } elseif (str_contains($e->getMessage(), 'Connection refused')) {
                    $this->warn('  Connection refused. The API endpoint might not exist.');
                }
            }
        }

        $this->error('All discovered API endpoints failed.');

        return 1;
    }

    /**
     * Discover API endpoints by simulating browser behavior and intercepting requests
     */
    protected function discoverApiEndpoints(string $filterId, int $page): array
    {
        $this->info('Discovering API endpoints through network analysis...');

        $searchUrl = "https://open.overheid.nl/zoeken?filter-id--organisatie={$filterId}&pagina={$page}";

        try {
            // Get the search page HTML
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification for development
                'timeout' => 20,
                'connect_timeout' => 10,
            ])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->get($searchUrl);

            if (! $response->successful()) {
                return [];
            }

            $html = $response->body();
            $discoveredApis = [];

            // Pattern 1: Look for Next.js API routes
            if (preg_match_all('/"buildId":"([^"]+)"/', $html, $matches)) {
                $buildId = $matches[1][0];
                $this->info("Found Next.js build ID: {$buildId}");

                // Common Next.js API patterns
                $nextjsApis = [
                    "https://open.overheid.nl/_next/data/{$buildId}/zoeken.json",
                    "https://open.overheid.nl/_next/data/{$buildId}/search.json",
                    'https://open.overheid.nl/api/search',
                ];

                foreach ($nextjsApis as $apiUrl) {
                    $discoveredApis[] = [
                        'url' => $apiUrl,
                        'params' => [
                            'filter-id--organisatie' => $filterId,
                            'pagina' => $page,
                        ],
                        'type' => 'nextjs',
                    ];
                }
            }

            // Pattern 2: Look for embedded API URLs in script tags
            if (preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $html, $scriptMatches)) {
                foreach ($scriptMatches[1] as $scriptContent) {
                    // Look for API endpoints in JavaScript
                    $apiPatterns = [
                        '/["\']([^"\']*\/api\/[^"\']*)["\']/',
                        '/["\']([^"\']*\.json[^"\']*)["\']/',
                        '/fetch\(["\']([^"\']+)["\']/',
                        '/axios\.get\(["\']([^"\']+)["\']/',
                    ];

                    foreach ($apiPatterns as $pattern) {
                        if (preg_match_all($pattern, $scriptContent, $apiMatches)) {
                            foreach ($apiMatches[1] as $apiUrl) {
                                if (str_contains($apiUrl, 'api') || str_contains($apiUrl, '.json')) {
                                    // Make URL absolute
                                    if (str_starts_with($apiUrl, '/')) {
                                        $apiUrl = 'https://open.overheid.nl'.$apiUrl;
                                    }

                                    $discoveredApis[] = [
                                        'url' => $apiUrl,
                                        'params' => [
                                            'organisation' => $filterId,
                                            'page' => $page,
                                        ],
                                        'type' => 'discovered',
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            // Pattern 3: Look for data attributes that might contain API info
            if (preg_match_all('/data-api[^=]*=["\']([^"\']+)["\']/', $html, $dataMatches)) {
                foreach ($dataMatches[1] as $apiUrl) {
                    if (str_starts_with($apiUrl, '/')) {
                        $apiUrl = 'https://open.overheid.nl'.$apiUrl;
                    }

                    $discoveredApis[] = [
                        'url' => $apiUrl,
                        'params' => [],
                        'type' => 'data-attribute',
                    ];
                }
            }

            // Remove duplicates
            $uniqueApis = [];
            foreach ($discoveredApis as $api) {
                $key = $api['url'];
                if (! isset($uniqueApis[$key])) {
                    $uniqueApis[$key] = $api;
                }
            }

            $this->info('Discovered '.count($uniqueApis).' potential API endpoints');
            foreach ($uniqueApis as $api) {
                $this->line("  - {$api['url']} ({$api['type']})");
            }

            return array_values($uniqueApis);

        } catch (\Exception $e) {
            $this->error("Error discovering API endpoints: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Try common API endpoint patterns as fallback
     */
    protected function tryCommonApiEndpoints(string $organisation, string $filterId, int $page, int $limit): int
    {
        $this->info('Trying common API endpoint patterns...');

        // Common API endpoint patterns based on typical React SPA structures
        // Focus on more realistic endpoints and avoid problematic SSL domains
        $apiEndpoints = [
            'https://open.overheid.nl/api/search',
            'https://open.overheid.nl/api/v1/search',
            'https://open.overheid.nl/api/zoeken',
            'https://open.overheid.nl/_next/data/search.json',
            'https://open.overheid.nl/search.json',
            // Note: Removed https://api.overheid.nl/search due to SSL issues
        ];

        foreach ($apiEndpoints as $endpoint) {
            $this->info("Trying API endpoint: {$endpoint}");

            try {
                $response = Http::withOptions([
                    'verify' => false, // Disable SSL verification for development
                    'timeout' => 15,
                    'connect_timeout' => 5,
                ])->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (compatible; ScrawlerBot/1.0)',
                ])->get($endpoint, [
                    'organisation' => $filterId,
                    'filter-id--organisatie' => $filterId,
                    'page' => $page,
                    'pagina' => $page,
                    'limit' => 20,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (! empty($data)) {
                        $this->info("✓ Found working API endpoint: {$endpoint}");
                        $this->info('Response structure: '.json_encode(array_keys($data), JSON_PRETTY_PRINT));

                        $documents = $this->parseApiResponse($data, $organisation);
                        if (! empty($documents)) {
                            return $this->storeDocuments($documents);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->line("  ✗ Failed: {$e->getMessage()}");

                // Provide specific guidance for common errors
                if (str_contains($e->getMessage(), 'SSL certificate')) {
                    $this->warn('  SSL certificate issue. This is common in local development.');
                    $this->info('  The command now uses verify=false to bypass SSL verification.');
                } elseif (str_contains($e->getMessage(), 'timeout')) {
                    $this->warn('  Request timeout. The endpoint might be slow or unavailable.');
                } elseif (str_contains($e->getMessage(), '404')) {
                    $this->line('  Endpoint not found (404). This is expected for non-existent APIs.');
                }
            }
        }

        $this->error('No working API endpoints found.');
        $this->info('Consider using --method=http or --method=external instead.');

        return 1;
    }

    /**
     * Parse API response similar to Python script's extract_formatted_metadata
     */
    protected function parseApiResponse(array $data, string $organisation): array
    {
        $documents = [];

        // Try different response structures
        $items = [];

        // Common API response patterns
        if (isset($data['results'])) {
            $items = $data['results'];
        } elseif (isset($data['documents'])) {
            $items = $data['documents'];
        } elseif (isset($data['items'])) {
            $items = $data['items'];
        } elseif (isset($data['data'])) {
            if (is_array($data['data'])) {
                $items = $data['data'];
            }
        } else {
            // If data is directly an array of items
            if (is_array($data) && ! empty($data)) {
                $items = $data;
            }
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Extract document information similar to Python script
            $document = [
                'organisation_suffix' => $organisation,
                'is_processed' => false,
                'fetch_timestamp' => now(),
            ];

            // Title extraction
            if (isset($item['title'])) {
                $document['title'] = $item['title'];
            } elseif (isset($item['officieleTitel'])) {
                $document['title'] = $item['officieleTitel'];
            } elseif (isset($item['titelcollectie']['officieleTitel'])) {
                $document['title'] = $item['titelcollectie']['officieleTitel'];
            }

            // URL extraction
            if (isset($item['url'])) {
                $document['source_url'] = $item['url'];
            } elseif (isset($item['weblocatie'])) {
                $document['source_url'] = $item['weblocatie'];
            } elseif (isset($item['detail_url'])) {
                $document['source_url'] = $item['detail_url'];
            } elseif (isset($item['pid'])) {
                $document['source_url'] = "https://open.overheid.nl/details/{$item['pid']}";
            }

            // Additional metadata extraction (similar to Python script)
            if (isset($item['pid'])) {
                $document['pid'] = $item['pid'];
            }

            if (isset($item['creatiedatum'])) {
                $document['publication_date'] = $item['creatiedatum'];
            }

            if (isset($item['documentsoort'])) {
                $document['document_type'] = $item['documentsoort'];
            }

            // Only add documents with required fields
            if (! empty($document['title']) && ! empty($document['source_url'])) {
                $documents[] = $document;
            }
        }

        $this->info('Parsed '.count($documents).' documents from API response');

        return $documents;
    }

    /**
     * Store documents in database with robust duplicate prevention
     */
    protected function storeDocuments(array $documents): int
    {
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        // Remove duplicates within the current batch first
        $uniqueDocuments = [];
        $seenUrls = [];
        
        foreach ($documents as $docData) {
            $url = $docData['source_url'];
            if (!isset($seenUrls[$url])) {
                $seenUrls[$url] = true;
                $uniqueDocuments[] = $docData;
            } else {
                $skippedCount++;
                $this->line("  Skipping duplicate URL in batch: {$url}");
            }
        }

        foreach ($uniqueDocuments as $docData) {
            Document::withoutSyncingToSearch(function () use ($docData, &$createdCount, &$updatedCount) {
                try {
                    // Use updateOrCreate for atomic upsert operation
                    $document = Document::updateOrCreate(
                        ['source_url' => $docData['source_url']], // Unique constraint
                        $docData // Data to update/create
                    );
                    
                    if ($document->wasRecentlyCreated) {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }
                } catch (\Exception $e) {
                    // Handle potential duplicate key errors gracefully
                    if (str_contains($e->getMessage(), 'duplicate') || str_contains($e->getMessage(), 'unique')) {
                        // Document already exists, try to update it
                        $document = Document::where('source_url', $docData['source_url'])->first();
                        if ($document) {
                            $document->update($docData);
                            $updatedCount++;
                        }
                    } else {
                        throw $e; // Re-throw if it's not a duplicate error
                    }
                }
            });
        }

        $this->info("✓ Stored documents: Created {$createdCount}, Updated {$updatedCount}");
        if ($skippedCount > 0) {
            $this->info("  Skipped {$skippedCount} duplicate URLs in current batch");
        }

        return 0;
    }

    /**
     * Crawl using external JavaScript rendering service
     */
    protected function crawlWithExternalRenderer(string $organisation, string $filterId, int $page, int $limit): int
    {
        $this->info('Using external rendering services...');

        $baseUrl = 'https://open.overheid.nl/zoeken';

        // Free services that can render JavaScript
        $renderingServices = [
            'https://api.scrapfly.io/scrape' => [
                'url' => "{$baseUrl}?filter-id--organisatie={$filterId}&pagina={$page}",
                'render_js' => 'true',
                'format' => 'html',
            ],
            // PhantomJSCloud (has free tier)
            'https://phantomjscloud.com/api/browser/v2/ak-DEMO-KEY/' => [
                'url' => "{$baseUrl}?filter-id--organisatie={$filterId}&pagina={$page}",
                'renderType' => 'html',
            ],
        ];

        foreach ($renderingServices as $serviceUrl => $params) {
            $this->info("Trying rendering service: {$serviceUrl}");

            try {
                $response = Http::timeout(30)->post($serviceUrl, $params);

                if ($response->successful()) {
                    $html = $response->body();

                    if (str_contains($html, 'result--list') || str_contains($html, 'resultaten_lijst')) {
                        $this->info('✓ Successfully rendered page with external service');

                        $documents = $this->parseHtmlDocuments($html, $organisation);

                        if (! empty($documents)) {
                            $this->info('Found '.count($documents).' documents');

                            $createdCount = 0;
                            $updatedCount = 0;

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
                            }

                            $this->info("Processed: Created {$createdCount}, Updated {$updatedCount}");

                            return 0;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->line("  ✗ Failed: {$e->getMessage()}");
            }
        }

        $this->error('External rendering services failed or require API keys.');
        $this->info('Consider signing up for a free tier at:');
        $this->info('- ScrapFly: https://scrapfly.io/');
        $this->info('- PhantomJSCloud: https://phantomjscloud.com/');

        return 1;
    }

    /**
     * Try to extract data from page source or network requests
     */
    protected function crawlWithNetworkInspection(string $baseUrl, string $organisation, string $filterId, int $page): int
    {
        $this->info('Inspecting network requests...');

        $url = "{$baseUrl}?filter-id--organisatie={$filterId}&pagina={$page}";

        try {
            // Get the initial page to inspect network calls
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->get($url);

            $html = $response->body();

            // Look for API calls in the page source
            $patterns = [
                '/fetch\(["\']([^"\']+api[^"\']*)["\']/',
                '/axios\.get\(["\']([^"\']+)["\']/',
                '/"apiUrl":\s*"([^"]+)"/',
                '/data-api-endpoint=["\']([^"\']+)["\']/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $html, $matches)) {
                    foreach ($matches[1] as $apiUrl) {
                        $this->info("Found potential API URL: {$apiUrl}");

                        // Try to call the discovered API
                        try {
                            $apiResponse = Http::withHeaders([
                                'Accept' => 'application/json',
                                'Referer' => $url,
                            ])->get($apiUrl);

                            if ($apiResponse->successful()) {
                                $data = $apiResponse->json();
                                if (! empty($data)) {
                                    $this->info("✓ Working API found: {$apiUrl}");
                                    $this->info('Response keys: '.implode(', ', array_keys($data)));

                                    return 0;
                                }
                            }
                        } catch (\Exception $e) {
                            // Continue to next API
                        }
                    }
                }
            }

            $this->warn('No API endpoints discovered in page source.');

            return 1;

        } catch (\Exception $e) {
            $this->error("Network inspection failed: {$e->getMessage()}");

            return 1;
        }
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
            ->appendOutputTo(storage_path('logs/openoverheid-crawl.log'));
    }
}
