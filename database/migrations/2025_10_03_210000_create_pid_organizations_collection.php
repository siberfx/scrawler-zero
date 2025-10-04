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
        Schema::connection('mongodb')->create('pid_organizations', function (Blueprint $collection) {
            $collection->index('resource_id');
            $collection->index('name');
            $collection->index('is_active');
            $collection->index('fetch_timestamp');
            $collection->index('last_updated');
            $collection->index('total_dossiers');
            $collection->index('total_documents');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            
            // Compound indexes for common queries
            $collection->index(['resource_id', 'is_active']);
            $collection->index(['is_active', 'total_dossiers']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('pid_organizations');
    }
};
