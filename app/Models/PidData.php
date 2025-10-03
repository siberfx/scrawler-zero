<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model for storing PID (Persistent Identifier) data from wooverheid.nl
 */
class PidData extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    protected $fillable = [
        'resource_id',
        'pid', // PID identifier for filtering and linking
        'pid_url',
        'total_dossiers',
        'dossiers_count',
        'metadata',
        'fetch_timestamp',
        'data_size',
        'is_processed',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'fetch_timestamp' => 'datetime',
        'processed_at' => 'datetime',
        'is_processed' => 'boolean',
        'metadata' => 'array',
        'total_dossiers' => 'integer',
        'dossiers_count' => 'integer',
        'data_size' => 'integer',
    ];

    protected $dates = [
        'fetch_timestamp',
        'processed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];


    /**
     * Scope a query to only include processed records.
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Scope a query to only include unprocessed records.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Get the total number of documents from metadata.
     */
    public function getTotalDocumentsAttribute(): int
    {
        return $this->metadata['total_documents'] ?? 0;
    }

    /**
     * Get document type statistics from metadata.
     */
    public function getDocumentTypeStatsAttribute(): array
    {
        return $this->metadata['document_types'] ?? [];
    }

    /**
     * Get year range from metadata.
     */
    public function getYearRangeAttribute(): array
    {
        return $this->metadata['year_range'] ?? ['min' => null, 'max' => null];
    }

    /**
     * Get formatted data size.
     */
    public function getFormattedDataSizeAttribute(): string
    {
        if (!$this->data_size) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->data_size;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
