<?php

namespace App\Commands;

use App\Models\OverheidOrganization;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchOverheidOrganizationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:overheid-organizations 
                            {--category= : Specific category to process (e.g., gemeenten, provincies)}
                            {--offset=0 : Starting offset for processing}
                            {--limit=1000 : Maximum number of records to process per run}
                            {--dry-run : Preview links without processing CSV files}
                            {--debug : Show debug information and save HTML content}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch CSV data from all "Direct naar" organization categories on organisaties.overheid.nl';

    /**
     * The main URL to scrape.
     *
     * @var string
     */
    protected $baseUrl = 'https://organisaties.overheid.nl/';

    /**
     * MongoDB collection name for storing organization data.
     *
     * @var string
     */
    protected $collectionName = 'overheid_organizations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fetching organization data from: ' . $this->baseUrl);
        
        try {
            // Get all "Direct naar" links
            $directLinks = $this->getDirectNaarLinks();
            
            if (empty($directLinks)) {
                $this->error('No "Direct naar" links found.');
                return 1;
            }

            $this->info('Found ' . count($directLinks) . ' "Direct naar" categories:');
            $this->displayCategories($directLinks);

            // Filter by specific category if provided
            $categoryFilter = $this->option('category');
            if ($categoryFilter) {
                $directLinks = array_filter($directLinks, function($link) use ($categoryFilter) {
                    return str_contains(strtolower($link['title']), strtolower($categoryFilter));
                });
                
                if (empty($directLinks)) {
                    $this->error("No categories found matching: {$categoryFilter}");
                    return 1;
                }
                
                $this->info("Filtered to " . count($directLinks) . " categories matching: {$categoryFilter}");
            }

            if ($this->option('dry-run')) {
                $this->info('Dry run completed. Use --dry-run=false to process CSV files.');
                return 0;
            }

            // Process each category
            $totalProcessed = 0;
            foreach ($directLinks as $link) {
                $this->info("\n🔄 Processing category: {$link['title']}");
                $processed = $this->processCategoryCSV($link);
                $totalProcessed += $processed;
                
                // Add small delay between categories
                sleep(2);
            }

            $this->info("\n✅ Processing completed. Total records processed: {$totalProcessed}");
            return 0;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('FetchOverheidOrganizationsCommand error', [
                'exception' => $e,
                'url' => $this->baseUrl,
            ]);
            return 1;
        }
    }

    /**
     * Get all "Direct naar" links from the homepage.
     */
    protected function getDirectNaarLinks(): array
    {
        $response = Http::withOptions([
            'verify' => false,
            'timeout' => 30,
            'connect_timeout' => 10,
        ])->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ])->get($this->baseUrl);

        if (!$response->successful()) {
            throw new \Exception("Failed to fetch homepage. Status: {$response->status()}");
        }

        $html = $response->body();
        
        // Debug: Save HTML content if debug flag is set
        if ($this->option('debug')) {
            $debugFile = storage_path('logs/homepage_debug.html');
            file_put_contents($debugFile, $html);
            $this->info("Debug: HTML content saved to {$debugFile}");
            $this->info("Debug: HTML length: " . strlen($html) . " characters");
        }
        
        // Validate HTML content
        if (empty($html) || strlen($html) < 100) {
            throw new \Exception('Invalid or empty HTML content received from homepage');
        }
        
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        
        // Try to load HTML with error handling
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            throw new \Exception('Failed to parse HTML content from homepage');
        }
        
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        if (!$xpath) {
            throw new \Exception('Failed to create XPath object');
        }
        $directLinks = [];

        // Look for "Direct naar" section links with comprehensive selectors
        $linkSelectors = [
            // Try to find all organization category links based on the image
            '//a[contains(@href, "/Gemeenten") or contains(@href, "/Provincies") or contains(@href, "/Waterschappen") or contains(@href, "/Ministeries") or contains(@href, "/Agentschappen") or contains(@href, "/Inspectie") or contains(@href, "/Adviescolleges") or contains(@href, "/Zelfstandige") or contains(@href, "/Politie") or contains(@href, "/Rechtspraak") or contains(@href, "/Overheidsstichtingen") or contains(@href, "/Externe") or contains(@href, "/Provinciale") or contains(@href, "/Regionale") or contains(@href, "/Gemeenschappelijke") or contains(@href, "/Grensoverschrijdende") or contains(@href, "/Koepelorganisaties") or contains(@href, "/Caribische") or contains(@href, "/Aruba") or contains(@href, "/Openbare") or contains(@href, "/Kabinet")]',
            // Look for links containing organization terms from the image
            '//a[contains(text(), "Gemeente") or contains(text(), "Provincie") or contains(text(), "Waterschap") or contains(text(), "Ministerie") or contains(text(), "Agentschap") or contains(text(), "Hoge Colleges") or contains(text(), "Interdepartementale") or contains(text(), "Inspectie") or contains(text(), "Adviescolleges") or contains(text(), "Zelfstandige bestuursorganen") or contains(text(), "Politie") or contains(text(), "Rechtspraak") or contains(text(), "Overheidsstichtingen") or contains(text(), "Externe commissies") or contains(text(), "Provinciale Rekenkamers") or contains(text(), "Regionale samenwerkingsorganen") or contains(text(), "Gemeenschappelijke regelingen") or contains(text(), "Grensoverschrijdende") or contains(text(), "Koepelorganisaties") or contains(text(), "Caribische") or contains(text(), "Aruba") or contains(text(), "Openbare lichamen") or contains(text(), "Kabinet")]',
            // Look for any internal links on the page
            '//a[starts-with(@href, "/") and string-length(@href) > 3]',
            // Generic approach - all links on the page
            '//a[@href and string-length(text()) > 3]',
        ];

        foreach ($linkSelectors as $selector) {
            $links = $xpath->query($selector);
            
            // Debug output
            if ($this->option('debug')) {
                $this->info("Debug: Testing selector: {$selector}");
                if ($links === false) {
                    $this->warn("Debug: Selector returned false (invalid XPath)");
                } else {
                    $this->info("Debug: Selector returned {$links->length} results");
                }
            }
            
            // Check if query was successful and returned results
            if ($links !== false && $links->length > 0) {
                $this->info("Found {$links->length} links using selector: {$selector}");
                
                foreach ($links as $link) {
                    $title = trim($link->textContent);
                    $href = $link->getAttribute('href');
                    
                    // Skip empty links
                    if (empty($title) || empty($href)) {
                        continue;
                    }
                    
                    // Skip navigation, footer, and irrelevant links
                    $skipTerms = ['home', 'contact', 'help', 'privacy', 'cookie', 'disclaimer', 'sitemap', 'rss', 'zoeken', 'search'];
                    $shouldSkip = false;
                    foreach ($skipTerms as $term) {
                        if (str_contains(strtolower($title), $term) || str_contains(strtolower($href), $term)) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    
                    if ($shouldSkip || strlen($title) < 3) {
                        continue;
                    }
                    
                    // Make URL absolute
                    if (!str_starts_with($href, 'http')) {
                        if (str_starts_with($href, '/')) {
                            $href = 'https://organisaties.overheid.nl' . $href;
                        } else {
                            $href = 'https://organisaties.overheid.nl/' . $href;
                        }
                    }
                    
                    // Skip duplicates
                    $key = strtolower($title);
                    if (!isset($directLinks[$key])) {
                        $directLinks[$key] = [
                            'title' => $title,
                            'url' => $href,
                            'slug' => Str::slug($title),
                        ];
                        
                        // Debug output for found links
                        if ($this->option('debug')) {
                            $this->line("Debug: Found link - Title: '{$title}', URL: '{$href}'");
                        }
                    }
                }
                
                // If we found links with this selector, break
                if (!empty($directLinks)) {
                    break;
                }
            }
        }

        return array_values($directLinks);
    }

    /**
     * Display found categories in a table.
     */
    protected function displayCategories(array $links): void
    {
        $headers = ['Title', 'URL', 'Slug'];
        $rows = [];

        foreach ($links as $link) {
            $rows[] = [
                $link['title'],
                Str::limit($link['url'], 60),
                $link['slug'],
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Process CSV data for a specific category.
     */
    protected function processCategoryCSV(array $categoryLink): int
    {
        $this->info("Visiting category page: {$categoryLink['url']}");
        
        try {
            // Get the category page
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
            ])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->get($categoryLink['url']);

            if (!$response->successful()) {
                $this->error("  ✗ Failed to fetch category page. Status: {$response->status()}");
                return 0;
            }

            // Find CSV export links
            $csvLinks = $this->findCSVLinks($response->body(), $categoryLink['url']);
            
            if (empty($csvLinks)) {
                $this->warn("  ⚠ No CSV export links found for: {$categoryLink['title']}");
                return 0;
            }

            $this->info("  Found " . count($csvLinks) . " CSV export links");
            
            $totalProcessed = 0;
            foreach ($csvLinks as $csvLink) {
                $processed = $this->downloadAndProcessCSV($csvLink, $categoryLink);
                $totalProcessed += $processed;
                
                // Small delay between CSV downloads
                sleep(1);
            }

            return $totalProcessed;

        } catch (\Exception $e) {
            $this->error("  ✗ Error processing category {$categoryLink['title']}: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Find CSV export links on a category page.
     */
    protected function findCSVLinks(string $html, string $baseUrl): array
    {
        // Validate HTML content
        if (empty($html) || strlen($html) < 50) {
            $this->warn('Invalid or empty HTML content for CSV link detection');
            return [];
        }
        
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        
        // Try to load HTML with error handling
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            $this->warn('Failed to parse HTML content for CSV link detection');
            return [];
        }
        
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        if (!$xpath) {
            $this->warn('Failed to create XPath object for CSV link detection');
            return [];
        }
        $csvLinks = [];

        // Look for CSV export links
        $csvSelectors = [
            '//a[contains(@href, ".csv") or contains(text(), "CSV") or contains(text(), "csv")]',
            '//a[contains(text(), "Exporteer") and contains(@href, "CSV")]',
            '//a[contains(@href, "export") and contains(@href, "csv")]',
            '//a[contains(@download, ".csv")]',
        ];

        foreach ($csvSelectors as $selector) {
            $links = $xpath->query($selector);
            
            // Check if query was successful
            if ($links !== false) {
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    $text = trim($link->textContent);
                    
                    if (!empty($href)) {
                        // Make URL absolute
                        if (!str_starts_with($href, 'http')) {
                            if (str_starts_with($href, '/')) {
                                $href = 'https://organisaties.overheid.nl' . $href;
                            } else {
                                $parsedBase = parse_url($baseUrl);
                                $href = $parsedBase['scheme'] . '://' . $parsedBase['host'] . '/' . ltrim($href, '/');
                            }
                        }
                        
                        $csvLinks[] = [
                            'url' => $href,
                            'text' => $text,
                        ];
                    }
                }
            }
        }

        // Remove duplicates
        $uniqueLinks = [];
        foreach ($csvLinks as $link) {
            $uniqueLinks[$link['url']] = $link;
        }

        return array_values($uniqueLinks);
    }

    /**
     * Download and process CSV file.
     */
    protected function downloadAndProcessCSV(array $csvLink, array $categoryLink): int
    {
        $this->info("    📥 Downloading CSV: {$csvLink['text']}");
        
        try {
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
            ])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/csv,application/csv,*/*',
            ])->get($csvLink['url']);

            if (!$response->successful()) {
                $this->error("    ✗ Failed to download CSV. Status: {$response->status()}");
                return 0;
            }

            $csvContent = $response->body();
            
            // Parse CSV content
            $records = $this->parseCSVContent($csvContent, $categoryLink, $csvLink);
            
            if (empty($records)) {
                $this->warn("    ⚠ No valid records found in CSV");
                return 0;
            }

            // Store records in MongoDB
            $stored = $this->storeRecords($records);
            
            $this->info("    ✅ Processed {$stored} records from CSV");
            return $stored;

        } catch (\Exception $e) {
            $this->error("    ✗ Error processing CSV: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Parse CSV content into structured records.
     */
    protected function parseCSVContent(string $csvContent, array $categoryLink, array $csvLink): array
    {
        $lines = str_getcsv($csvContent, "\n");
        if (empty($lines)) {
            return [];
        }

        // Get headers from first line - handle semicolon separator
        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine, ';');
        
        // If semicolon parsing didn't work, try comma
        if (count($headers) <= 1) {
            $headers = str_getcsv($headerLine, ',');
        }
        
        if (empty($headers)) {
            return [];
        }

        // Clean headers - remove quotes and extra spaces
        $headers = array_map(function($header) {
            return trim($header, '"');
        }, $headers);

        // Debug: Show CSV structure
        if ($this->option('debug')) {
            $this->info("    Debug: CSV Headers: " . implode(', ', $headers));
            if (!empty($lines)) {
                $firstRow = str_getcsv($lines[0]);
                if (count($firstRow) === count($headers)) {
                    $sampleData = array_combine($headers, $firstRow);
                    $this->info("    Debug: Sample row: " . json_encode($sampleData, JSON_UNESCAPED_UNICODE));
                }
            }
        }

        $records = [];
        $offset = (int) $this->option('offset');
        $limit = (int) $this->option('limit');
        $processed = 0;

        foreach ($lines as $index => $line) {
            // Apply offset
            if ($index < $offset) {
                continue;
            }
            
            // Apply limit
            if ($processed >= $limit) {
                break;
            }

            // Parse line with same separator as headers
            $values = str_getcsv($line, ';');
            
            // If semicolon parsing didn't work, try comma
            if (count($values) <= 1) {
                $values = str_getcsv($line, ',');
            }
            
            if (count($values) !== count($headers)) {
                continue; // Skip malformed rows
            }

            // Clean values - remove quotes and extra spaces
            $values = array_map(function($value) {
                return trim($value, '"');
            }, $values);

            $rawData = array_combine($headers, $values);
            
            // Map CSV columns to standardized database columns
            $record = $this->mapCSVToStandardColumns($rawData, $categoryLink, $csvLink, $index);

            $records[] = $record;
            $processed++;
        }

        return $records;
    }

    /**
     * Map CSV columns to standardized database columns
     */
    protected function mapCSVToStandardColumns(array $csvData, array $categoryLink, array $csvLink, int $rowIndex): array
    {
        // Common column mappings (case-insensitive) - expanded with specific Dutch government CSV columns
        $columnMappings = [
            'name' => [
                // Standard variations
                'naam', 'name', 'organisatie', 'organization', 'bedrijfsnaam', 'instelling', 'organisatienaam', 
                'gemeente', 'provincie', 'waterschap', 'ministerie', 'agentschap', 'titel', 'title',
                // Specific Dutch government CSV columns
                'Naam', 'Organisatie (onderdeel)', 'organisatie (onderdeel)'
            ],
            'contact_person' => [
                'contactpersoon', 'contact_person', 'aanspreekpunt', 'contact', 'persoon',
                // Specific Dutch government CSV columns  
                'Naam', 'naam'
            ],
            'description' => [
                'beschrijving', 'description', 'omschrijving', 'toelichting', 'info', 'informatie',
                // Specific Dutch government CSV columns
                'Functie', 'functie'
            ],
            'code' => [
                'code', 'nummer', 'id', 'identificatie', 'cbs_code', 'gemeentecode', 'provinciecode', 'organisatiecode', 'ref', 'reference',
                // Specific Dutch government CSV columns
                'Resource identifier v4.0 organisatie', 'Resource identifier v5.0 organisatie',
                'resource identifier v4.0 organisatie', 'resource identifier v5.0 organisatie'
            ],
            'website' => ['website', 'url', 'webadres', 'internetadres', 'site', 'webpagina', 'homepage'],
            'address' => ['adres', 'address', 'straat', 'bezoekadres', 'vestigingsadres', 'locatie', 'location', 'straatadres'],
            'phone' => ['telefoon', 'phone', 'tel', 'telefoonnummer', 'telefon', 'mobiel', 'mobile'],
            'email' => ['email', 'e-mail', 'emailadres', 'mail', 'e_mail', 'contact_email'],
            'postcode' => ['postcode', 'postal_code', 'zip', 'pc'],
            'city' => ['plaats', 'city', 'woonplaats', 'stad', 'gemeente_naam', 'vestigingsplaats'],
            'province' => ['provincie', 'province', 'regio', 'provincie_naam'],
            'fax' => ['fax', 'faxnummer', 'telefax'],
            'po_box' => ['postbus', 'po_box', 'postadres', 'pb'],
            'establishment_date' => ['oprichtingsdatum', 'establishment_date', 'datum_oprichting', 'opgericht', 'founded'],
            'legal_form' => ['rechtsvorm', 'legal_form', 'vorm', 'type_organisatie'],
            'chamber_of_commerce' => ['kvk', 'kvk_nummer', 'chamber_of_commerce', 'handelsregister', 'kvk_nr'],
            'vat_number' => ['btw_nummer', 'vat_number', 'btw', 'btw_nr'],
            'budget' => ['budget', 'begroting', 'financien', 'omzet', 'turnover'],
            'employees' => ['medewerkers', 'employees', 'personeel', 'fte', 'werknemers', 'staff'],
            'sector' => ['sector', 'branche', 'categorie', 'type', 'soort'],
            'parent_organization' => ['moederorganisatie', 'parent_organization', 'hoofdorganisatie', 'moeder', 'parent'],
        ];

        $record = [
            // Category information
            'category_title' => $categoryLink['title'],
            'category_slug' => $categoryLink['slug'],
            'category_url' => $categoryLink['url'],
            
            // Source tracking
            'csv_source_url' => $csvLink['url'],
            'csv_source_text' => $csvLink['text'],
            'row_index' => $rowIndex,
            'imported_at' => now(),
            
            // Store original CSV data
            'raw_data' => $csvData,
        ];

        // Debug: Show available CSV columns
        if ($this->option('debug')) {
            $this->line("    Debug: Available CSV columns: " . implode(', ', array_keys($csvData)));
        }

        // Map CSV columns to standard columns
        foreach ($columnMappings as $standardColumn => $possibleColumns) {
            $value = $this->findColumnValue($csvData, $possibleColumns);
            if ($value !== null) {
                $record[$standardColumn] = $this->cleanValue($value, $standardColumn);
                
                if ($this->option('debug')) {
                    $this->line("    Debug: Mapped '{$standardColumn}' = '{$value}'");
                }
            }
        }

        // If no name was found, try to use the first non-empty column as name
        if (empty($record['name'])) {
            foreach ($csvData as $column => $value) {
                if (!empty(trim($value)) && strlen(trim($value)) > 2) {
                    $record['name'] = trim($value);
                    if ($this->option('debug')) {
                        $this->line("    Debug: Using '{$column}' as name: '{$record['name']}'");
                    }
                    break;
                }
            }
        }

        // Generate unique key for deduplication
        $uniqueData = [
            'category' => $categoryLink['slug'],
            'name' => $record['name'] ?? '',
            'code' => $record['code'] ?? '',
            'address' => $record['address'] ?? '',
        ];
        $record['unique_key'] = md5(json_encode($uniqueData));

        return $record;
    }

    /**
     * Find value from CSV data using possible column names
     */
    protected function findColumnValue(array $csvData, array $possibleColumns): ?string
    {
        foreach ($possibleColumns as $column) {
            // Try exact match first
            if (isset($csvData[$column])) {
                return trim($csvData[$column]);
            }
            
            // Try case-insensitive match
            foreach ($csvData as $csvColumn => $value) {
                if (strtolower($csvColumn) === strtolower($column)) {
                    return trim($value);
                }
            }
        }
        
        return null;
    }

    /**
     * Clean and format values based on column type
     */
    protected function cleanValue(?string $value, string $columnType): ?string
    {
        if (empty($value) || $value === '-' || $value === 'n.v.t.' || $value === 'N/A') {
            return null;
        }

        $value = trim($value);

        switch ($columnType) {
            case 'website':
                // Ensure website has protocol
                if (!empty($value) && !str_starts_with($value, 'http')) {
                    $value = 'https://' . $value;
                }
                break;
                
            case 'email':
                // Basic email validation
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }
                break;
                
            case 'phone':
                // Clean phone number
                $value = preg_replace('/[^\d\+\-\(\)\s]/', '', $value);
                break;
                
            case 'postcode':
                // Format Dutch postal code
                $value = strtoupper(preg_replace('/\s+/', ' ', $value));
                break;
                
            case 'budget':
            case 'employees':
                // Extract numbers only
                $value = preg_replace('/[^\d\.]/', '', $value);
                break;
        }

        return $value ?: null;
    }

    /**
     * Store records using Laravel model.
     */
    protected function storeRecords(array $records): int
    {
        try {
            $stored = 0;
            
            foreach ($records as $recordData) {
                // Use Laravel model with updateOrCreate for deduplication
                $organization = OverheidOrganization::updateOrCreate(
                    ['unique_key' => $recordData['unique_key']],
                    $recordData
                );
                
                if ($organization->wasRecentlyCreated || $organization->wasChanged()) {
                    $stored++;
                    
                    if ($this->option('debug')) {
                        $action = $organization->wasRecentlyCreated ? 'Created' : 'Updated';
                        $this->line("    {$action}: {$organization->name} ({$organization->category_title})");
                    }
                }
            }
            
            return $stored;
            
        } catch (\Exception $e) {
            $this->error("Error storing records: {$e->getMessage()}");
            Log::error('FetchOverheidOrganizationsCommand storage error', [
                'exception' => $e,
                'records_count' => count($records),
            ]);
            return 0;
        }
    }
}
