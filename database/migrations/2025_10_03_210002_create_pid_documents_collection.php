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
        Schema::connection('mongodb')->create('pid_documents', function (Blueprint $collection) {
            $collection->index('organization_id');
            $collection->index('dossier_id');
            $collection->index('dc_identifier');
            $collection->index('dc_type');
            $collection->index('dc_format');
            $collection->index('file_extension');
            $collection->index('is_downloaded');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            
            // Compound indexes for common queries
            $collection->index(['organization_id', 'dossier_id']);
            $collection->index(['organization_id', 'dc_type']);
            $collection->index(['dossier_id', 'dc_type']);
            $collection->index(['organization_id', 'is_downloaded']);
            $collection->index(['dc_format', 'is_downloaded']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('pid_documents');
    }
};
