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
        Schema::connection('mongodb')->create('organizations', function (Blueprint $collection) {
            $collection->index('name');
            $collection->index('slug');
            $collection->index('type');
            $collection->index('is_active');
            $collection->index('organization_category_id');
            $collection->index('details_processed');
            $collection->index('province');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            
            // Compound indexes for common queries
            $collection->index(['type', 'is_active']);
            $collection->index(['is_active', 'details_processed']);
            $collection->index(['province', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->drop('organizations');
    }
};
