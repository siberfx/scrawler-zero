<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for storing individual PID Document data from wooverheid.nl
 */
class PidDocument extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'dc_identifier',
        'dc_title',
        'dc_type',
        'dc_source',
        'dc_format',
        'foi_file_name',
        'file_extension',
        'file_size',
        'is_downloaded',
        'download_url',
        'local_path',
        'checksum',
        'metadata',
    ];

    protected $casts = [
        'is_downloaded' => 'boolean',
        'file_size' => 'integer',
        'metadata' => 'array',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the organization that owns this document.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PidOrganization::class, 'organization_id');
    }

    /**
     * Get the dossier that contains this document.
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(PidDossier::class, 'dossier_id');
    }

    /**
     * Scope a query to only include downloaded documents.
     */
    public function scopeDownloaded($query)
    {
        return $query->where('is_downloaded', true);
    }

    /**
     * Scope a query to only include documents of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('dc_type', $type);
    }

    /**
     * Scope a query to only include documents with a specific format.
     */
    public function scopeOfFormat($query, $format)
    {
        return $query->where('dc_format', $format);
    }

    /**
     * Get the document's display title.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->dc_title ?? $this->foi_file_name ?? $this->dc_identifier;
    }

    /**
     * Get the file extension from format or filename.
     */
    public function getFileExtensionAttribute(): string
    {
        if ($this->file_extension) {
            return $this->file_extension;
        }

        if ($this->dc_format) {
            $formatMap = [
                'text/plain' => 'txt',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            ];
            
            return $formatMap[$this->dc_format] ?? 'unknown';
        }

        if ($this->foi_file_name) {
            return pathinfo($this->foi_file_name, PATHINFO_EXTENSION) ?: 'unknown';
        }

        return 'unknown';
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->file_size;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get the file icon based on the file extension.
     */
    public function getFileIconAttribute(): string
    {
        $extension = strtolower($this->file_extension_attribute);

        $icons = [
            'pdf' => 'file-pdf',
            'doc' => 'file-word',
            'docx' => 'file-word',
            'xls' => 'file-excel',
            'xlsx' => 'file-excel',
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
