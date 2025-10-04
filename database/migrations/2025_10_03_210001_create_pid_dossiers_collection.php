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
        Schema::connection('mongodb')->create('pid_dossiers', function (Blueprint $collection) {
            $collection->index('organization_id');
            $collection->index('dc_identifier');
            $collection->index('dc_type');
            $collection->index('dc_type_description');
            $collection->index('dc_date_year');
            $collection->index('foi_nr_documents');
            $collection->index('foi_start_date');
            $collection->index('foi_update_date');
            $collection->index('created_at');
            $collection->index('updated_at');
            $collection->index('deleted_at');
            
            // Compound indexes for common queries
            $collection->index(['organization_id', 'dc_type']);
            $collection->index(['organization_id', 'dc_date_year']);
            $collection->index(['dc_type', 'dc_date_year']);
            $collection->index(['organization_id', 'foi_nr_documents']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('pid_dossiers');
    }
};
