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
        Schema::connection('mongodb')->create('organization_relations', function (Blueprint $collection) {
            $collection->index('organization_id');
            $collection->index('type');
            $collection->index('relatie_type');
            $collection->index('naam');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            
            // Compound indexes for common queries
            $collection->index(['organization_id', 'type']);
            $collection->index(['organization_id', 'relatie_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->drop('organization_relations');
    }
};
