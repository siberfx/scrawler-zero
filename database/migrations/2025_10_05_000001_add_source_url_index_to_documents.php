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
        Schema::connection('mongodb')->table('documents', function (Blueprint $collection) {
            // Add unique index on source_url to prevent URL duplicates
            $collection->unique('source_url');
            
            // Add regular index for faster lookups
            $collection->index('source_url');
            
            // Add compound index for common queries with source_url
            $collection->index(['source_url', 'is_processed']);
            $collection->index(['source_url', 'organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->table('documents', function (Blueprint $collection) {
            $collection->dropIndex(['source_url']);
            $collection->dropIndex(['source_url', 'is_processed']);
            $collection->dropIndex(['source_url', 'organization_id']);
        });
    }
};
