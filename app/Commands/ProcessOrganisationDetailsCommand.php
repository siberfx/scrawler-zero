<?php

namespace App\Commands;

use App\Models\Organization as Organisation;
use App\Models\OrganizationAddress;
use App\Models\OrganizationRelation;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessOrganisationDetailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'organizations:process-details
                            {--limit=1000 : Number of organisations to process in one run (0 for all)}
                            {--id= : Process a specific organisation by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process organisation details by visiting their URLs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting organisation details processor...');

        // Process a specific organisation if ID is provided
        if ($id = $this->option('id')) {
            $organisation = Organisation::find($id);

            if (! $organisation) {
                $this->error("Organisation with ID {$id} not found.");

                return 1;
            }

            $this->processOrganisation($organisation);
            $this->info("Processed organisation: {$organisation->name}");

            return 0;
        }

        // Get organisations to process
        $query = Organisation::query()
            ->where('details_processed', false);

        // Prioritize unprocessed organizations first
        $query->orderBy('last_processed_at', 'asc');

        // Get the limit
        $limit = (int) $this->option('limit');

        $processed = 0;
        $failed = 0;

        // If limit is set to 0 or not provided, process all organizations using chunk
        if ($limit <= 0) {
            $this->info('Processing all organizations using chunk method...');

            // Use chunk to process all organizations in batches of 100
            $query->chunk(100, function ($organisations) use (&$processed, &$failed) {
                $this->info("Processing batch of {$organisations->count()} organisations...");

                foreach ($organisations as $organisation) {
                    try {
                        $this->processOrganisation($organisation);
                        $processed++;
                        $this->info("Processed {$processed} organizations so far, failed: {$failed}");
                    } catch (Exception $e) {
                        $failed++;
                        Log::error("Failed to process organisation {$organisation->name}: {$e->getMessage()}", [
                            'exception' => $e,
                            'organisation_id' => $organisation->id,
                        ]);

                        // Continue with next organisation
                        $this->error("Failed to process {$organisation->name}: {$e->getMessage()}");
                    }
                }
            });

            $this->info("Completed processing all organizations. Processed: {$processed}, Failed: {$failed}");

            return 0;
        }

        // Process with limit using regular collection
        $organisations = $query->take($limit)->get();

        if ($organisations->isEmpty()) {
            $this->info('No organisations to process.');

            return 0;
        }

        $this->info("Processing {$organisations->count()} organisations...");

        $bar = $this->output->createProgressBar($organisations->count());
        $bar->start();

        foreach ($organisations as $organisation) {
            try {
                $this->processOrganisation($organisation);
                $processed++;
            } catch (Exception $e) {
                $failed++;
                Log::error("Failed to process organisation {$organisation->name}: {$e->getMessage()}", [
                    'exception' => $e,
                    'organisation_id' => $organisation->id,
                ]);

                // Continue with next organisation
                $this->error("\nFailed to process {$organisation->name}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Processed: {$processed}, Failed: {$failed}");

        return 0;
    }

    /**
     * Process a single organisation.
     */
    protected function processOrganisation(Organisation $organisation): void
    {
        $this->line("\nProcessing organisation: {$organisation->name}");

        // Skip processing if the organization doesn't have a valid URL or ID
        if (empty($organisation->url) || strpos($organisation->url, 'organisaties.overheid.nl') === false) {
            $this->warn("Skipping organization with invalid URL: {$organisation->name}");
            $organisation->update([
                'details_processed' => true,
                'last_processed_at' => now(),
            ]);

            return;
        }

        // Get the organisation ID from raw_data or URL
        $id = $organisation->raw_data['id'] ?? null;

        // If ID is not in raw_data, try to extract it from the URL
        if (! $id && ! empty($organisation->url)) {
            // Extract ID from URL patterns like https://organisaties.overheid.nl/12345 or https://organisaties.overheid.nl/woo/12345
            if (preg_match('/organisaties\.overheid\.nl(?:\/woo)?\/(\d+)/i', $organisation->url, $matches)) {
                $id = $matches[1];
            }
        }

        if (! $id) {
            $this->warn("Organisation ID not found for {$organisation->name}, marking as processed");
            $organisation->update([
                'details_processed' => true,
                'last_processed_at' => now(),
            ]);

            return;
        }

        // Try different URL formats
        $urls = [
            "https://organisaties.overheid.nl/{$id}",
            $organisation->url,
        ];

        // Check if the URL contains the WOO prefix
        $hasWooPrefix = strpos($organisation->url, 'organisaties.overheid.nl/woo/') !== false;

        // Add WOO URL variants
        $urls[] = "https://organisaties.overheid.nl/woo/{$id}";

        // Add a URL with the name part if available
        $namePart = '';
        if (! empty($organisation->url)) {
            $urlParts = explode('/', $organisation->url);
            $namePart = end($urlParts);
            if (! empty($namePart)) {
                $urls[] = "https://organisaties.overheid.nl/{$id}/{$namePart}";
            }
        }

        $response = null;
        $successUrl = null;

        // Try each URL until one succeeds
        foreach ($urls as $url) {
            $this->info("Trying URL: {$url}");
            $response = Http::get($url);

            if ($response->successful()) {
                $successUrl = $url;
                $this->info("Successfully accessed URL: {$url}");
                break;
            }
        }

        if (! $response || ! $response->successful()) {
            $this->warn("Failed to fetch any URL for {$organisation->name}, marking as processed");
            $organisation->update([
                'details_processed' => true,
                'last_processed_at' => now(),
            ]);

            return;
        }

        $html = $response->body();

        // Parse the HTML
        $details = $this->parseOrganisationDetails($html);

        if (empty($details)) {
            throw new Exception("No details found for organisation: {$organisation->name}");
        }

        // Extract address and relation data from details
        $addresses = $this->extractAddresses($details);
        $relations = $this->extractRelations($details);

        // Store addresses in the relational model
        foreach ($addresses as $addressData) {
            OrganizationAddress::create([
                'organization_id' => $organisation->id,
                'type' => $addressData['type'] ?? 'bezoekadres',
                'straat' => $addressData['straat'] ?? null,
                'huisnummer' => $addressData['huisnummer'] ?? null,
                'postbus' => $addressData['postbus'] ?? null,
                'postcode' => $addressData['postcode'] ?? null,
                'plaats' => $addressData['plaats'] ?? null,
            ]);
        }

        // Store relations in the relational model
        foreach ($relations as $relationData) {
            OrganizationRelation::create([
                'organization_id' => $organisation->id,
                'type' => $relationData['type'] ?? 'other',
                'naam' => $relationData['naam'] ?? null,
                'relatie_type' => $relationData['relatie_type'] ?? null,
            ]);
        }

        // Store addresses and relations in raw_data for search indexing
        $rawData = $organisation->raw_data;
        $rawData['addresses'] = $addresses;
        $rawData['relations'] = $relations;

        // Update the organisation with the details
        $organisation->update([
            'details' => $details,
            'details_processed' => true,
            'last_processed_at' => now(),
            'raw_data' => $rawData,
        ]);

        $this->info(sprintf(
            'Processed %s: %d addresses, %d relations',
            $organisation->name,
            count($addresses),
            count($relations)
        ));

        // Sleep briefly to avoid overwhelming the server
        usleep(500000); // 0.5 seconds
    }

    /**
     * Parse the HTML content to extract organisation details.
     */
    protected function parseOrganisationDetails(string $html): array
    {
        $details = [];

        // Create a new DOM document
        $dom = new DOMDocument;

        // Suppress warnings for malformed HTML
        @$dom->loadHTML($html);

        // Create XPath object
        $xpath = new DOMXPath($dom);

        // Extract data from specific sections using anchor links
        $sections = [
            'organisatiegegevens' => '#organisatiegegevens',
            'beschrijving' => '#beschrijving',
            'contactgegevens' => '#contactgegevens',
            'indienen_woo_verzoek' => '#indienen-woo-verzoek',
            'functies_organisatie' => '#functies-organisatie',
            'locaties_woo_documenten' => '#locaties-woo-documenten',
        ];

        foreach ($sections as $key => $anchor) {
            $details[$key] = $this->extractSectionData($xpath, $anchor);
        }

        return $details;
    }

    /**
     * Extract data from a specific section based on its anchor link.
     */
    protected function extractSectionData(DOMXPath $xpath, string $anchor): array
    {
        $details = [];

        // Find the section by its ID (remove the # from the anchor)
        $sectionId = substr($anchor, 1);
        $section = $xpath->query("//section[@id='{$sectionId}']");

        // If not found by ID, try finding by heading text
        if (! $section->length) {
            // Convert kebab-case to Title Case for heading search
            $headingText = ucwords(str_replace('-', ' ', $sectionId));
            $section = $xpath->query("//h2[contains(text(), '{$headingText}')]/..");
        }

        if ($section->length > 0) {
            // Get section title
            $titleNodes = $xpath->query('.//h2', $section->item(0));
            if ($titleNodes->length > 0) {
                $details['title'] = $this->cleanText($titleNodes->item(0)->textContent);
            }

            // Get all table rows in the section
            $rows = $xpath->query('.//table//tr', $section->item(0));

            if ($rows->length > 0) {
                // Process table data
                foreach ($rows as $row) {
                    // Get the header (key) and data (value) cells
                    $th = $xpath->query('.//th', $row);
                    $td = $xpath->query('.//td', $row);

                    if ($th->length > 0 && $td->length > 0) {
                        $key = trim($th->item(0)->textContent);

                        // Skip empty keys
                        if (empty($key)) {
                            continue;
                        }

                        // Process the value cell
                        $valueNode = $td->item(0);
                        $value = $this->processValueNode($xpath, $valueNode);

                        // Store in the details array using a normalized key
                        $normalizedKey = $this->normalizeKey($key);
                        $details['table_data'][$normalizedKey] = $value;
                    }
                }
            } else {
                // If no table, extract paragraphs and lists
                $paragraphs = $xpath->query('.//p', $section->item(0));
                if ($paragraphs->length > 0) {
                    $textContent = [];
                    foreach ($paragraphs as $p) {
                        $textContent[] = $this->cleanText($p->textContent);
                    }
                    $details['text'] = $textContent;
                }

                // Extract lists
                $lists = $xpath->query('.//ul|.//ol', $section->item(0));
                if ($lists->length > 0) {
                    $listItems = [];
                    foreach ($lists as $list) {
                        $items = $xpath->query('.//li', $list);
                        foreach ($items as $item) {
                            $listItems[] = $this->cleanText($item->textContent);
                        }
                    }
                    $details['list_items'] = $listItems;
                }
            }

            // Extract links that are not in tables
            $links = $xpath->query('.//a[not(ancestor::table)]', $section->item(0));
            if ($links->length > 0) {
                $linkData = [];
                foreach ($links as $link) {
                    $linkData[] = [
                        'text' => $this->cleanText($link->textContent),
                        'url' => $link->getAttribute('href'),
                        'is_external' => $link->getAttribute('class') === 'is-external',
                    ];
                }
                $details['links'] = $linkData;
            }
        }

        return $details;
    }

    protected function extractLabeledValue(DOMXPath $xpath, string $label, \DOMNode $context): ?string
    {
        $nodes = $xpath->query(".//tr[th[contains(text(), '{$label}')]]/td", $context);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Extract a labeled link from the page.
     */
    protected function extractLabeledLink(DOMXPath $xpath, string $label, \DOMNode $context): ?string
    {
        $nodes = $xpath->query(".//tr[th[contains(text(), '{$label}')]]/td//a", $context);
        if ($nodes->length > 0) {
            return $nodes->item(0)->getAttribute('href');
        }

        return null;
    }

    /**
     * Process a value node to extract text and links.
     *
     * @return array|string
     */
    protected function processValueNode(DOMXPath $xpath, \DOMNode $node)
    {
        $result = [];

        // Check if there are any links in the node
        $links = $xpath->query('.//a', $node);

        if ($links->length > 0) {
            // If there are links, extract them with their text
            foreach ($links as $link) {
                $linkText = trim($link->textContent);
                $linkHref = $link->getAttribute('href');

                if (! empty($linkText) && ! empty($linkHref)) {
                    $result['links'][] = [
                        'text' => $linkText,
                        'url' => $linkHref,
                        'is_external' => $link->getAttribute('class') === 'is-external',
                    ];
                }
            }
        }

        // Get the full text content and clean it up
        $text = $this->cleanText($node->textContent);
        if (! empty($text)) {
            $result['text'] = $text;
        }

        // If we only have text, return just the text
        if (count($result) === 1 && isset($result['text'])) {
            return $result['text'];
        }

        return $result;
    }

    /**
     * Normalize a key for consistent storage.
     */
    protected function normalizeKey(string $key): string
    {
        // Convert to lowercase
        $key = strtolower($key);

        // Replace spaces and special characters with underscores
        $key = preg_replace('/[\s\-\/]+/', '_', $key);

        // Remove any remaining non-alphanumeric characters
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        return $key;
    }

    /**
     * Clean up text by removing excessive whitespace and formatting.
     */
    protected function cleanText(string $text): string
    {
        // Trim whitespace
        $text = trim($text);

        // Replace multiple whitespace characters with a single space
        $text = preg_replace('/\s+/', ' ', $text);
        // Replace multiple newlines with a single newline
        $text = preg_replace('/\n+/', "\n", $text);
        // Remove tab characters
        $text = str_replace("\t", '', $text);

        // Replace multiple spaces after newlines
        $text = preg_replace('/\n\s+/', "\n", $text);

        return trim($text);
    }

    /**
     * Extract address data from organization details.
     */
    protected function extractAddresses(array $details): array
    {
        $addresses = [];

        // First check if organisatiegegevens section exists and has table_data
        if (isset($details['organisatiegegevens']['table_data'])) {
            $orgData = $details['organisatiegegevens']['table_data'];

            // Extract postal address if available
            if (isset($orgData['postadres'])) {
                $postAddress = is_array($orgData['postadres']) ? ($orgData['postadres']['text'] ?? '') : $orgData['postadres'];

                if (! empty($postAddress)) {
                    $addresses[] = [
                        'type' => 'postadres',
                        'postbus' => null,
                        'postcode' => null,
                        'plaats' => null,
                        'full_address' => $postAddress,
                    ];
                }
            }

            // Extract visiting address if available
            if (isset($orgData['bezoekadres'])) {
                $visitAddress = is_array($orgData['bezoekadres']) ? ($orgData['bezoekadres']['text'] ?? '') : $orgData['bezoekadres'];

                if (! empty($visitAddress)) {
                    $addresses[] = [
                        'type' => 'bezoekadres',
                        'straat' => null,
                        'huisnummer' => null,
                        'postcode' => null,
                        'plaats' => null,
                        'full_address' => $visitAddress,
                    ];
                }
            }
        }

        // Also check if contactgegevens section exists and has table_data
        if (isset($details['contactgegevens']['table_data'])) {
            $contactData = $details['contactgegevens']['table_data'];

            // Extract postal address if available and not already extracted
            if (isset($contactData['postadres']) && ! $this->hasAddressType($addresses, 'postadres')) {
                $postAddress = is_array($contactData['postadres']) ? ($contactData['postadres']['text'] ?? '') : $contactData['postadres'];

                // Try to parse the address format
                if (! empty($postAddress)) {
                    // Split by newlines to get different parts
                    $parts = explode("\n", str_replace('<br>', "\n", $postAddress));

                    $addressData = [
                        'type' => 'postadres',
                        'postbus' => null,
                        'postcode' => null,
                        'plaats' => null,
                        'full_address' => $postAddress,
                    ];

                    // Try to extract postbus, postcode, and plaats
                    foreach ($parts as $part) {
                        $part = trim($part);

                        // Check for postbus
                        if (preg_match('/^postbus\s+(\d+)$/i', $part, $matches)) {
                            $addressData['postbus'] = $matches[1];
                        }
                        // Check for postcode and plaats
                        elseif (preg_match('/^([0-9]{4}\s*[a-z]{2})\s+(.+)$/i', $part, $matches)) {
                            $addressData['postcode'] = $matches[1];
                            $addressData['plaats'] = $matches[2];
                        }
                    }

                    $addresses[] = $addressData;
                }
            }

            // Extract visiting address if available and not already extracted
            if (isset($contactData['bezoekadres']) && ! $this->hasAddressType($addresses, 'bezoekadres')) {
                $visitAddress = is_array($contactData['bezoekadres']) ? ($contactData['bezoekadres']['text'] ?? '') : $contactData['bezoekadres'];

                // Try to parse the address format
                if (! empty($visitAddress)) {
                    // Split by newlines to get different parts
                    $parts = explode("\n", str_replace('<br>', "\n", $visitAddress));

                    $addressData = [
                        'type' => 'bezoekadres',
                        'straat' => null,
                        'huisnummer' => null,
                        'postcode' => null,
                        'plaats' => null,
                        'full_address' => $visitAddress,
                    ];

                    // Try to extract street, house number, postcode, and plaats
                    if (count($parts) >= 1) {
                        // First part usually contains street and house number
                        $streetPart = trim($parts[0]);
                        if (preg_match('/^(.+?)\s+(\d+.*)$/i', $streetPart, $matches)) {
                            $addressData['straat'] = $matches[1];
                            $addressData['huisnummer'] = $matches[2];
                        } else {
                            $addressData['straat'] = $streetPart;
                        }

                        // Second part usually contains postcode and plaats
                        if (count($parts) >= 2) {
                            $postalPart = trim($parts[1]);
                            if (preg_match('/^([0-9]{4}\s*[a-z]{2})\s+(.+)$/i', $postalPart, $matches)) {
                                $addressData['postcode'] = $matches[1];
                                $addressData['plaats'] = $matches[2];
                            } else {
                                $addressData['plaats'] = $postalPart;
                            }
                        }
                    }

                    $addresses[] = $addressData;
                }
            }
        }

        return $addresses;
    }

    /**
     * Check if addresses array already has an address of the specified type
     */
    protected function hasAddressType(array $addresses, string $type): bool
    {
        foreach ($addresses as $address) {
            if ($address['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract relation data from organization details.
     */
    protected function extractRelations(array $details): array
    {
        $relations = [];

        // Check if functies_organisatie section exists
        if (isset($details['functies_organisatie'])) {
            $functiesData = $details['functies_organisatie'];

            // Extract relations from table_data if available
            if (isset($functiesData['table_data'])) {
                foreach ($functiesData['table_data'] as $key => $value) {
                    if (is_string($value)) {
                        $relations[] = [
                            'type' => 'functie',
                            'naam' => $value,
                            'relatie_type' => $key,
                        ];
                    }
                }
            }

            // Extract relations from list_items if available
            if (isset($functiesData['list_items'])) {
                foreach ($functiesData['list_items'] as $item) {
                    $relations[] = [
                        'type' => 'functie',
                        'naam' => $item,
                        'relatie_type' => 'list_item',
                    ];
                }
            }
        }

        // Check if organisatiegegevens section exists for parent/child relations
        if (isset($details['organisatiegegevens']['table_data'])) {
            $orgData = $details['organisatiegegevens']['table_data'];

            // Check for parent organization
            if (isset($orgData['valt_onder'])) {
                $relations[] = [
                    'type' => 'parent',
                    'naam' => is_string($orgData['valt_onder']) ? $orgData['valt_onder'] : null,
                    'relatie_type' => 'valt_onder',
                ];
            }
        }

        return $relations;
    }
}
