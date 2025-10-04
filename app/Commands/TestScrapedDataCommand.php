<?php

namespace App\Commands;

use Illuminate\Console\Command;
use MongoDB\Client;

class TestScrapedDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:scraped-data 
                            {--limit=5 : Number of records to display}
                            {--processed : Show only processed URLs}
                            {--unprocessed : Show only unprocessed URLs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test and display scraped data from MongoDB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        $processed = $this->option('processed');
        $unprocessed = $this->option('unprocessed');

        try {
            // Connect to MongoDB
            $client = new Client('mongodb://root:14396Oem0012443@212.132.107.72:27017/');
            $db = $client->scrawler;
            $collection = $db->urls;

            // Build query
            $query = [];
            if ($processed) {
                $query['processed'] = true;
            } elseif ($unprocessed) {
                $query['processed'] = false;
            }

            // Get total counts
            $totalCount = $collection->countDocuments([]);
            $processedCount = $collection->countDocuments(['processed' => true]);
            $unprocessedCount = $collection->countDocuments(['processed' => false]);

            $this->info("=== MongoDB URLs Collection Status ===");
            $this->info("Total URLs: {$totalCount}");
            $this->info("Processed: {$processedCount}");
            $this->info("Unprocessed: {$unprocessedCount}");
            $this->newLine();

            // Get sample records
            $cursor = $collection->find($query, ['limit' => $limit]);
            $records = $cursor->toArray();

            if (empty($records)) {
                $this->warn('No records found matching criteria.');
                return 0;
            }

            foreach ($records as $i => $record) {
                $this->info("=== Record " . ($i + 1) . " ===");
                $this->info("URL: " . ($record['url'] ?? 'N/A'));
                $this->info("Processed: " . ($record['processed'] ? 'Yes' : 'No'));
                
                if (isset($record['created_at'])) {
                    $this->info("Created: " . $record['created_at']->toDateTime()->format('Y-m-d H:i:s'));
                }
                
                if (isset($record['processed_at'])) {
                    $this->info("Processed: " . $record['processed_at']->toDateTime()->format('Y-m-d H:i:s'));
                }

                // Show raw scraped data info
                if (isset($record['raw_scraped_data'])) {
                    $rawData = $record['raw_scraped_data'];
                    $this->info("Raw Data: Available");
                    
                    if (isset($rawData['captured']) && is_array($rawData['captured'])) {
                        $this->info("  - Captured responses: " . count($rawData['captured']));
                        
                        foreach ($rawData['captured'] as $j => $capture) {
                            $this->info("  - Response " . ($j + 1) . ": " . ($capture['response_url'] ?? 'N/A'));
                            
                            if (isset($capture['data']['document'])) {
                                $doc = $capture['data']['document'];
                                $title = $doc['titelcollectie']['officieleTitel'] ?? 'N/A';
                                $this->info("    Title: " . substr($title, 0, 80) . (strlen($title) > 80 ? '...' : ''));
                            }
                        }
                    }
                } else {
                    $this->warn("Raw Data: Not available");
                }

                // Show formatted metadata
                if (isset($record['formatted_metadata'])) {
                    $metadata = $record['formatted_metadata'];
                    $this->info("Formatted Metadata: Available");
                    $this->info("  - Title: " . substr($metadata['officiele_titel'] ?? 'N/A', 0, 80));
                    $this->info("  - Organization: " . ($metadata['verantwoordelijke_label'] ?? 'N/A'));
                    $this->info("  - Document Type: " . ($metadata['documentsoort'] ?? 'N/A'));
                    $this->info("  - Publication Date: " . ($metadata['gepubliceerd_op'] ?? 'N/A'));
                    $this->info("  - Language: " . ($metadata['taal'] ?? 'N/A'));
                    $this->info("  - File Type: " . ($metadata['bestandstype'] ?? 'N/A'));
                    
                    if (isset($metadata['themas'])) {
                        $this->info("  - Themes: " . substr($metadata['themas'], 0, 60));
                    }
                } else {
                    $this->warn("Formatted Metadata: Not available");
                }

                $this->newLine();
            }

            // Show organizations data if available
            $orgCollection = $db->organizations;
            $orgCount = $orgCollection->countDocuments([]);
            $orgProcessedCount = $orgCollection->countDocuments(['details_processed' => true]);
            
            $this->info("=== Organizations Status ===");
            $this->info("Total Organizations: {$orgCount}");
            $this->info("Details Processed: {$orgProcessedCount}");

            // Show addresses data
            $addressCollection = $db->organization_addresses;
            $addressCount = $addressCollection->countDocuments([]);
            $nonEmptyAddresses = $addressCollection->countDocuments([
                '$or' => [
                    ['straat' => ['$ne' => null]],
                    ['postbus' => ['$ne' => null]],
                    ['postcode' => ['$ne' => null]],
                    ['plaats' => ['$ne' => null]]
                ]
            ]);
            
            $this->info("Total Addresses: {$addressCount}");
            $this->info("Non-empty Addresses: {$nonEmptyAddresses}");

            return 0;

        } catch (\Exception $e) {
            $this->error('Error connecting to MongoDB: ' . $e->getMessage());
            return 1;
        }
    }
}
