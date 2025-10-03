<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for storing PID Dossier data from wooverheid.nl
 */
class PidDossier extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    protected $fillable = [
        'organization_id',
        'dc_identifier',
        'dc_title',
        'dc_type',
        'dc_type_description',
        'dc_description',
        'dc_source',
        'dc_publisher',
        'dc_creator',
        'dc_date_year',
        'foi_start_date',
        'foi_update_date',
        'foi_retrieved_date',
        'foi_published_date',
        'foi_nr_documents',
        'foi_nr_pages_in_dossier',
        'foi_fairiscore_versions',
        'metadata',
    ];

    protected $casts = [
        'foi_start_date' => 'date',
        'foi_update_date' => 'date',
        'foi_retrieved_date' => 'date',
        'foi_published_date' => 'date',
        'dc_date_year' => 'integer',
        'foi_nr_documents' => 'integer',
        'foi_nr_pages_in_dossier' => 'integer',
        'foi_fairiscore_versions' => 'array',
        'metadata' => 'array',
    ];

    protected $dates = [
        'foi_start_date',
        'foi_update_date',
        'foi_retrieved_date',
        'foi_published_date',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the organization that owns this dossier.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PidOrganization::class, 'organization_id');
    }

    /**
     * Get all documents in this dossier.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PidDocument::class, 'dossier_id');
    }

    /**
     * Scope a query to only include dossiers of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('dc_type', $type);
    }

    /**
     * Scope a query to only include dossiers from a specific year.
     */
    public function scopeFromYear($query, $year)
    {
        return $query->where('dc_date_year', $year);
    }

    /**
     * Get the dossier's display title.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->dc_title ?? $this->dc_identifier;
    }

    /**
     * Get the dossier's type description or fallback to type.
     */
    public function getTypeDisplayAttribute(): string
    {
        return $this->dc_type_description ?? $this->dc_type ?? 'Unknown';
    }

    /**
     * Check if the dossier has documents.
     */
    public function getHasDocumentsAttribute(): bool
    {
        return $this->foi_nr_documents > 0;
    }
}
