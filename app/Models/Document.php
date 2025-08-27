<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use MongoDB\Laravel\Eloquent\Model;
use Siberfx\Typesense\Interfaces\TypesenseDocument;

/**
 * @mixin IdeHelperDocument
 */
class Document extends Model implements TypesenseDocument
{
    use Searchable, SoftDeletes;

    protected $connection = 'mongodb';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'roo_identifier',
        'source_url',
        'title',
        'publication_date',
        'document_type',
        'government_entity_id',
        'government_entity_name',
        'organisation_suffix',
        'archived_file_path',
        'fetch_timestamp',
        'last_checked_timestamp',
        'content_hash',
        'status_code',
        'content_type_header',
        'error_message',
        'extracted_keywords',
        'case_references',
        'summary',
        'entities',
        'metadata',
        'language',
        'is_processed',
        'processed_at',
        'file_size',
        'file_extension',
        'checksum',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'publication_date' => 'date',
        'fetch_timestamp' => 'datetime',
        'last_checked_timestamp' => 'datetime',
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'metadata' => 'array',
        'entities' => 'array',
        'case_references' => 'array',
        'extracted_keywords' => 'array',
    ];

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $array = [
            'id' => (string) $this->id, // Convert ID to string for Typesense
            'roo_identifier' => $this->roo_identifier,
            'title' => $this->title,
            'document_type' => $this->document_type,
            'publication_date' => $this->publication_date ? $this->publication_date->toDateString() : null,
            'summary' => $this->summary,
            'extracted_keywords' => is_array($this->extracted_keywords) ? implode(', ', $this->extracted_keywords) : $this->extracted_keywords,
            'case_references' => is_array($this->case_references) ? implode(', ', $this->case_references) : $this->case_references,
            'government_entity' => $this->governmentEntity ? $this->governmentEntity->name : null,
            'language' => $this->language,
            'is_processed' => $this->is_processed ? 1 : 0,
            'file_extension' => $this->file_extension,
            'file_size' => $this->file_size,
            'created_at' => $this->created_at ? $this->created_at->timestamp : time(), // Ensure created_at is always present
            'updated_at' => $this->updated_at ? $this->updated_at->timestamp : null,
        ];

        // Filter out null values
        return array_filter($array, function ($value) {
            return $value !== null;
        });
    }

    /**
     * Get the name of the index associated with the model.
     *
     * @return string
     */
    public function searchableAs()
    {
        return 'documents';
    }

    /**
     * Determine if the model should be searchable.
     *
     * @return bool
     */
    public function shouldBeSearchable()
    {
        return $this->is_processed;
    }

    /**
     * Get the collection schema for Typesense.
     */
    public function getCollectionSchema(): array
    {
        $schema = config('scout.typesense.model-settings.'.self::class.'.collection-schema');
        $schema['name'] = $this->searchableAs();

        // Make sure created_at is not optional since it's used as default sorting field
        foreach ($schema['fields'] as &$field) {
            if ($field['name'] === 'created_at' && isset($field['optional'])) {
                unset($field['optional']);
            }
        }

        return $schema;
    }

    /**
     * The fields to be queried against.
     */
    public function typesenseQueryBy(): array
    {
        return ['title', 'summary', 'extracted_keywords', 'case_references'];
    }

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'publication_date',
        'fetch_timestamp',
        'last_checked_timestamp',
        'processed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the government entity that owns the document.
     */
    public function governmentEntity(): BelongsTo
    {
        return $this->belongsTo(GovernmentEntity::class);
    }

    /**
     * Get the organization that owns the document.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include processed documents.
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Scope a query to only include unprocessed documents.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope a query to only include documents of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('document_type', $type);
    }

    /**
     * Get the file size in a human-readable format.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->file_size) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->file_size;
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf('%.2f', $bytes / (1024 ** $factor)).' '.$units[$factor];
    }

    /**
     * Get the document's status.
     */
    public function getStatusAttribute(): string
    {
        if ($this->is_processed) {
            return 'Processed';
        }

        if ($this->error_message) {
            return 'Error';
        }

        return 'Pending';
    }

    /**
     * Get the status badge class for the document.
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return [
            'Processed' => 'bg-green-100 text-green-800',
            'Error' => 'bg-red-100 text-red-800',
            'Pending' => 'bg-yellow-100 text-yellow-800',
        ][$this->status] ?? 'bg-gray-100 text-gray-800';
    }

    /**
     * Get the file name from the archived file path.
     */
    public function getFileNameAttribute(): string
    {
        if (! $this->archived_file_path) {
            return 'N/A';
        }

        return basename($this->archived_file_path);
    }

    /**
     * Get the file icon based on the file extension.
     */
    public function getFileIconAttribute(): string
    {
        $extension = strtolower($this->file_extension);

        $icons = [
            'pdf' => 'file-pdf',
            'doc' => 'file-word',
            'docx' => 'file-word',
            'xls' => 'file-excel',
            'xlsx' => 'file-excel',
            'ppt' => 'file-powerpoint',
            'pptx' => 'file-powerpoint',
            'txt' => 'file-alt',
            'csv' => 'file-csv',
            'zip' => 'file-archive',
            'rar' => 'file-archive',
            'jpg' => 'file-image',
            'jpeg' => 'file-image',
            'png' => 'file-image',
            'gif' => 'file-image',
        ];

        return $icons[$extension] ?? 'file';
    }
}
