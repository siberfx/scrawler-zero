<?php

namespace App\Commands;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationAddress;
use App\Models\OrganizationCategory;
use App\Models\OrganizationRelation;
use App\Services\OrganizationDataService;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Console\Scheduling\Schedule;

class CrawlOrganisationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'organizations:crawl
                            {--selector=//a : XPath selector for finding links}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl the organizations website and store links in the database';

    /**
     * The URL to crawl.
     *
     * @var string
     */
    protected $url;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting organization crawler...');

        // Create categories for all organization types if they don't exist
        $this->createOrganizationTypeCategories();

        $totalSuccess = 0;
        $totalFailed = 0;
        $selector = $this->option('selector');

        // Loop through all organization types
        foreach (OrganizationType::cases() as $type) {
            $this->info("\nCrawling {$type->label()} ({$type->value})...");
            $this->url = $type->fullUrl();

            try {
                $this->info("Fetching URL: {$this->url}");
                $response = Http::get($this->url);

                if (! $response->successful()) {
                    $this->error("Failed to fetch URL: {$this->url}. Status code: {$response->status()}");
                    $totalFailed++;

                    continue;
                }

                $html = $response->body();

                // Parse the HTML
                $organizations = $this->parseOrganizations($html, $type);

                if (empty($organizations)) {
                    $this->error("No organizations found on the page for {$type->label()}.");
                    $totalFailed++;

                    continue;
                }

                $this->info('Found '.count($organizations).' organizations.');

                // Store in database
                $this->storeOrganizations($organizations);

                $this->info("Organizations for {$type->label()} crawled and stored successfully.");
                $totalSuccess++;
            } catch (\Exception $e) {
                $this->error("Error crawling {$type->label()}: {$e->getMessage()}");
                Log::error("Organization crawler error for {$type->label()}: {$e->getMessage()}", [
                    'exception' => $e,
                    'url' => $this->url,
                    'type' => $type->value,
                ]);
                $totalFailed++;
            }

            // Add a small delay between requests to avoid overwhelming the server
            sleep(1);
        }

        $this->info("\nCrawling completed. Successfully crawled {$totalSuccess} types, failed {$totalFailed} types.");

        return ($totalFailed > 0) ? 1 : 0;
    }

    /**
     * Create organization categories based on OrganizationType enum values
     */
    protected function createOrganizationTypeCategories(): void
    {
        $this->info('Creating organization type categories...');
        $categoriesCreated = 0;

        foreach (OrganizationType::cases() as $type) {
            $categoryName = $type->label();
            $category = OrganizationCategory::firstOrCreate(['name' => $categoryName]);

            if ($category->wasRecentlyCreated) {
                $categoriesCreated++;
            }
        }

        $this->info("Created {$categoriesCreated} new organization type categories.");
    }

    /**
     * Parse the HTML content to extract organizations.
     */
    protected function parseOrganizations(string $html, OrganizationType $type): array
    {
        $organizations = [];

        // Create a new DOM document
        $dom = new DOMDocument;

        // Suppress warnings for malformed HTML
        @$dom->loadHTML($html);

        // Create XPath object
        $xpath = new DOMXPath($dom);

        // Get the selector from the command option or use default for new structure
        $selector = $this->option('selector') ?: '//li//a[contains(@href, "organisaties.overheid.nl")]';

        // Find all links using the provided selector
        $links = $xpath->query($selector);

        $this->info("Found {$links->length} links using selector: {$selector}");

        // Look for category in page title or header elements
        $titleNodes = $xpath->query('//h1 | //title');
        if ($titleNodes->length > 0) {
            $pageTitle = trim($titleNodes->item(0)->textContent);

            $this->info("Detected category: {$type->label()}");
        }

        foreach ($links as $link) {
            $name = trim($link->textContent);
            $url = $link->getAttribute('href');

            // Skip empty links or non-relevant links
            if (empty($url) || empty($name)) {
                continue;
            }

            // Check if the URL is a relative path to an organization detail page
            if (preg_match('/^\/(\d+)\/(.+)$/', $url, $matches)) {
                // Convert relative URL to absolute URL
                $url = 'https://organisaties.overheid.nl'.$url;
            }
            // Check if the URL is a relative path with /woo/ prefix
            elseif (preg_match('/^\/woo\/(\d+)\/(.+)$/', $url, $matches)) {
                // Convert relative URL to absolute URL with /woo/ prefix
                $url = 'https://organisaties.overheid.nl'.$url;
            }
            // Check if the URL matches either the regular or WOO URL pattern
            elseif (! preg_match('/organisaties\.overheid\.nl(\/woo)?\/(\d+)\//', $url)) {
                $this->line("Skipping non-organization URL: {$url}");

                continue;
            }

            // Add the base URL prefix if the URL is relative
            if (! Str::startsWith($url, ['http://', 'https://'])) {
                $url = 'https://organisaties.overheid.nl'.(Str::startsWith($url, '/') ? '' : '/').$url;
            }

            // Extract the ID from the URL for later use
            $urlParts = explode('/', $url);
            $id = $urlParts[count($urlParts) - 2] ?? null;

            // Create organization data structure with placeholders for addresses and relations
            // These will be populated with real data when processing organization details
            $organizations[] = [
                'name' => $name,
                'abbreviation' => OrganizationDataService::generateAcronym($name),
                'url' => $url,
                'slug' => Str::slug($name),
                'type' => $type->value,
                'category' => $type->label(), // Store the category name for later processing
                'raw_data' => [
                    'crawled_at' => now()->toIso8601String(),
                    'source_url' => $this->url,
                    'id' => $id,
                    'type' => $type->value,
                    'category' => $type->label(), // Also store in raw data for reference
                    // Add empty arrays for addresses and relations
                    // These will be populated by the ProcessOrganisationDetailsCommand
                    'addresses' => [],
                    'relations' => [],
                ],
            ];
        }

        return $organizations;
    }

    /**
     * Store organizations in the database.
     */
    protected function storeOrganizations(array $organizations): void
    {
        $bar = $this->output->createProgressBar(count($organizations));
        $bar->start();

        $created = 0;
        $updated = 0;
        $addressesCreated = 0;
        $relationsCreated = 0;
        $categoriesCreated = 0;

        foreach ($organizations as $data) {
            // Extract addresses and relations from raw_data if available
            $addresses = [];
            $relations = [];

            if (isset($data['raw_data']['addresses'])) {
                $addresses = $data['raw_data']['addresses'];
                unset($data['raw_data']['addresses']);
            }

            if (isset($data['raw_data']['relations'])) {
                $relations = $data['raw_data']['relations'];
                unset($data['raw_data']['relations']);
            }

            // Handle category based on both page-detected category and organization type
            $categoryId = null;
            $typeValue = $data['type'] ?? null;

            // First try to use the page-detected category if available
            if (! empty($data['category'])) {
                // Only find existing category by name - don't create new ones
                $category = OrganizationCategory::where('name', $data['category'])->first();

                if ($category) {
                    $categoryId = $category->id;
                }
            }

            // If no page-detected category or it wasn't found, use the organization type as category
            if (! $categoryId && $typeValue) {
                $type = OrganizationType::tryFrom($typeValue);
                if ($type) {
                    $typeCategoryName = $type->label();
                    // Only find existing type category - don't create new ones
                    $typeCategory = OrganizationCategory::where('name', $typeCategoryName)->first();

                    if ($typeCategory) {
                        $categoryId = $typeCategory->id;
                    }
                }
            }

            // Remove category from data array as it's not a direct field in the organization table
            unset($data['category']);
            $data['details_processed'] = false;

            // Set the organization_category_id for the organization if a category was found or created
            if ($categoryId) {
                $data['organization_category_id'] = $categoryId;
            }

            // Check if organization already exists
            $organization = Organization::query()->where('slug', $data['slug'])->first();

            if ($organization) {
                // Update existing organization
                $organization->update($data);
                $updated++;
            } else {
                // Create new organization
                $organization = Organization::query()->create($data);
                $created++;
            }

            $organization->addresses()->truncate();

            // Process and store addresses
            foreach ($addresses as $addressData) {
                OrganizationAddress::create([
                    'organization_id' => $organization->id,
                    'type' => $addressData['type'] ?? 'bezoekadres',
                    'straat' => $addressData['straat'] ?? null,
                    'huisnummer' => $addressData['huisnummer'] ?? null,
                    'postbus' => $addressData['postbus'] ?? null,
                    'postcode' => $addressData['postcode'] ?? null,
                    'plaats' => $addressData['plaats'] ?? null,
                ]);

                $addressesCreated++;
            }

            $organization->relations()->truncate();

            // Process and store relations
            foreach ($relations as $relationData) {
                OrganizationRelation::create([
                    'organization_id' => $organization->id,
                    'type' => $relationData['type'] ?? 'other',
                    'naam' => $relationData['naam'] ?? null,
                    'relatie_type' => $relationData['relatieType'] ?? null,
                ]);

                $relationsCreated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Organizations - Created: {$created}, Updated: {$updated}");
        if ($addressesCreated > 0 || $relationsCreated > 0 || $categoriesCreated > 0) {
            $this->info("Addresses created: {$addressesCreated}, Relations created: {$relationsCreated}, Categories created: {$categoriesCreated}");
        }
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class)
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(base_path('logs/organizations-crawl.log'));
    }
}
