<?php

namespace App\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestMongoConnectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:mongo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MongoDB connection and show database info';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing MongoDB connection...');
        
        try {
            // Test basic connection
            $connection = DB::connection('mongodb');
            $this->info('✓ MongoDB connection established');
            
            // Get database name
            $dbName = $connection->getDatabaseName();
            $this->info("✓ Connected to database: {$dbName}");
            
            // List collections
            $collections = $connection->getMongoClient()->selectDatabase($dbName)->listCollections();
            $collectionNames = [];
            foreach ($collections as $collection) {
                $collectionNames[] = $collection->getName();
            }
            
            $this->info('✓ Available collections: ' . implode(', ', $collectionNames));
            
            // Test Document model connection
            $documentsCount = Document::count();
            $this->info("✓ Documents collection count: {$documentsCount}");
            
            // Check if urls collection exists (from Python script)
            if (in_array('urls', $collectionNames)) {
                $urlsCollection = $connection->getCollection('urls');
                $urlsCount = $urlsCollection->count();
                $this->info("✓ URLs collection count: {$urlsCount}");
                
                // Show sample URL document
                $sampleUrl = $urlsCollection->findOne();
                if ($sampleUrl) {
                    $this->info('✓ Sample URL document structure:');
                    $this->line('  - url: ' . ($sampleUrl['url'] ?? 'N/A'));
                    $this->line('  - processed: ' . ($sampleUrl['processed'] ? 'true' : 'false'));
                    $this->line('  - created_at: ' . ($sampleUrl['created_at'] ?? 'N/A'));
                }
            } else {
                $this->warn('⚠ URLs collection not found (Python script collection)');
            }
            
            $this->info('✓ MongoDB connection test completed successfully!');
            
        } catch (\Exception $e) {
            $this->error('✗ MongoDB connection failed: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}
