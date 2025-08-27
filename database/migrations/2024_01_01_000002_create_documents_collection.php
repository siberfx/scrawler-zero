<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        Schema::connection('mongodb')->create('documents', function (Blueprint $collection) {
            $collection->index('roo_identifier');
            $collection->index('title');
            $collection->index('document_type');
            $collection->index('government_entity_id');
            $collection->index('organization_id');
            $collection->index('publication_date');
            $collection->index('is_processed');
            $collection->index('language');
            $collection->index('file_extension');
            $collection->index('status_code');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            $collection->index('processed_at');
            
            // Compound indexes for common queries
            $collection->index(['is_processed', 'document_type']);
            $collection->index(['organization_id', 'is_processed']);
            $collection->index(['publication_date', 'document_type']);
            $collection->index(['government_entity_id', 'publication_date']);
            
            // Text search indexes
            $collection->index(['title' => 'text', 'summary' => 'text']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->drop('documents');
    }
};
