<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for storing PID Organization data from wooverheid.nl
 */
class PidOrganization extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    protected $fillable = [
        'resource_id',
        'pid_url',
        'name',
        'total_dossiers',
        'total_documents',
        'fetch_timestamp',
        'last_updated',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'fetch_timestamp' => 'datetime',
        'last_updated' => 'datetime',
        'is_active' => 'boolean',
        'total_dossiers' => 'integer',
        'total_documents' => 'integer',
        'metadata' => 'array',
    ];

    protected $dates = [
        'fetch_timestamp',
        'last_updated',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get all dossiers for this organization.
     */
    public function dossiers(): HasMany
    {
        return $this->hasMany(PidDossier::class, 'organization_id');
    }

    /**
     * Get all documents for this organization through dossiers.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PidDocument::class, 'organization_id');
    }

    /**
     * Scope a query to only include active organizations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the organization's name or resource ID as fallback.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->resource_id;
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
}
