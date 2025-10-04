<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     *
     * @var string
     */
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get the MongoDB collection directly to check existing indexes
        $collection = DB::connection('mongodb')->getCollection('documents');
        $existingIndexes = $collection->listIndexes();
        
        $indexNames = [];
        foreach ($existingIndexes as $index) {
            $indexNames[] = $index->getName();
        }
        
        // Only create indexes that don't exist
        Schema::connection('mongodb')->table('documents', function (Blueprint $table) use ($indexNames) {
            // Add compound indexes if they don't exist
            if (!in_array('source_url_is_processed_idx', $indexNames)) {
                $table->index(['source_url', 'is_processed'], 'source_url_is_processed_idx');
            }
            
            if (!in_array('source_url_organization_id_idx', $indexNames)) {
                $table->index(['source_url', 'organization_id'], 'source_url_organization_id_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->table('documents', function (Blueprint $collection) {
            try {
                $collection->dropIndex('source_url_is_processed_idx');
            } catch (\Exception $e) {
                // Index might not exist
            }
            
            try {
                $collection->dropIndex('source_url_organization_id_idx');
            } catch (\Exception $e) {
                // Index might not exist
            }
        });
    }
};
