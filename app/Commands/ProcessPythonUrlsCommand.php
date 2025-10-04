<?php

namespace App\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessPythonUrlsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:python-urls 
                            {--limit=50 : Maximum number of URLs to process}
                            {--processed : Process already processed URLs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process URLs collected by Python script and create Document records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        $includeProcessed = $this->option('processed');
        
        $this->info('Processing URLs from Python script...');
        
        try {
            // Connect to the same database as Python script
            $connection = DB::connection('mongodb');
            $urlsCollection = $connection->getCollection('urls');
            
            // Build query
            $query = [];
            if (!$includeProcessed) {
                $query['processed'] = true; // Get processed URLs with scraped data
            }
            
            // Get URLs from Python collection
            $urls = $urlsCollection->find($query)->limit($limit);
            $urlsArray = iterator_to_array($urls);
            
            if (empty($urlsArray)) {
                $this->warn('No URLs found to process.');
                return 0;
            }
            
            $this->info('Found ' . count($urlsArray) . ' URLs to process');
            
            $createdCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            
            foreach ($urlsArray as $urlDoc) {
                try {
                    $documentData = $this->convertUrlToDocument($urlDoc);
                    
                    if ($documentData) {
                        // Check if document already exists
                        $existingDoc = Document::where('source_url', $documentData['source_url'])->first();
                        
                        if ($existingDoc) {
                            $existingDoc->update($documentData);
                            $updatedCount++;
                            $this->line("  ✓ Updated: {$documentData['title']}");
                        } else {
                            Document::create($documentData);
                            $createdCount++;
                            $this->line("  ✓ Created: {$documentData['title']}");
                        }
                    } else {
                        $errorCount++;
                        $this->line("  ✗ Skipped: {$urlDoc['url']} (no valid data)");
                    }
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("  ✗ Error processing {$urlDoc['url']}: {$e->getMessage()}");
                }
            }
            
            $this->info("Processing completed:");
            $this->info("  Created: {$createdCount}");
            $this->info("  Updated: {$updatedCount}");
            $this->info("  Errors: {$errorCount}");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error connecting to MongoDB: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Convert Python URL document to Laravel Document format
     */
    protected function convertUrlToDocument(array $urlDoc): ?array
    {
        // Check if URL has been processed by Python script
        if (!isset($urlDoc['formatted_metadata']) || empty($urlDoc['formatted_metadata'])) {
            return null;
        }
        
        $metadata = $urlDoc['formatted_metadata'];
        
        // Extract basic information
        $documentData = [
            'source_url' => $urlDoc['url'],
            'title' => $metadata['officiele_titel'] ?? $metadata['title'] ?? 'Untitled',
            'fetch_timestamp' => $urlDoc['created_at'] ?? now(),
            'is_processed' => true,
            'processed_at' => $urlDoc['processed_at'] ?? now(),
        ];
        
        // Add optional fields if they exist
        if (!empty($metadata['creatiedatum'])) {
            try {
                $documentData['publication_date'] = \Carbon\Carbon::parse($metadata['creatiedatum']);
            } catch (\Exception $e) {
                // Ignore date parsing errors
            }
        }
        
        if (!empty($metadata['documentsoort'])) {
            $documentData['document_type'] = $metadata['documentsoort'];
        }
        
        if (!empty($metadata['verantwoordelijke_label'])) {
            $documentData['government_entity_name'] = $metadata['verantwoordelijke_label'];
        }
        
        if (!empty($metadata['publicerende_organisatie'])) {
            $documentData['organisation_suffix'] = $metadata['publicerende_organisatie'];
        }
        
        if (!empty($metadata['taal'])) {
            $documentData['language'] = $metadata['taal'];
        }
        
        if (!empty($metadata['bestandsgrootte'])) {
            $documentData['file_size'] = $metadata['bestandsgrootte'];
        }
        
        if (!empty($metadata['bestandstype'])) {
            $documentData['file_extension'] = strtolower($metadata['bestandstype']);
        }
        
        // Store all metadata for reference
        $documentData['metadata'] = [
            'python_scraped_data' => $metadata,
            'pid' => $metadata['pid'] ?? null,
            'weblocatie' => $metadata['weblocatie'] ?? null,
            'download_url' => $metadata['download_url'] ?? null,
            'themas' => $metadata['themas'] ?? null,
            'informatiecategorieen' => $metadata['informatiecategorieen'] ?? [],
        ];
        
        return $documentData;
    }
}
