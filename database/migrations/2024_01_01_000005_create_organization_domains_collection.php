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
        Schema::connection('mongodb')->create('organization_domains', function (Blueprint $collection) {
            $collection->index('organization_id');
            $collection->index('uri');
            $collection->index('status');
            $collection->index('last_checked_at');
            $collection->index('created_at');
            $collection->index('updated_at');
            
            // Compound indexes for common queries
            $collection->index(['organization_id', 'status']);
            $collection->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->drop('organization_domains');
    }
};
