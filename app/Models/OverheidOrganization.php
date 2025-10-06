<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class OverheidOrganization extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'overheid_organizations';

    protected $fillable = [
        // Common organization fields
        'name',
        'code',
        'website',
        'address',
        'phone',
        'email',
        'postcode',
        'city',
        'province',
        'description',

        // Additional fields that might be in CSV
        'contact_person',
        'fax',
        'po_box',
        'establishment_date',
        'legal_form',
        'chamber_of_commerce',
        'vat_number',
        'budget',
        'employees',
        'sector',
        'parent_organization',

        // Category information
        'category_title',
        'category_slug',
        'category_url',

        // Source tracking
        'csv_source_url',
        'csv_source_text',
        'row_index',
        'imported_at',

        // Raw data backup
        'raw_data',

        // Unique identifier for deduplication
        'unique_key',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'establishment_date' => 'date',
        'raw_data' => 'array',
        'budget' => 'decimal:2',
        'employees' => 'integer',
        'row_index' => 'integer',
    ];

    protected $dates = [
        'imported_at',
        'establishment_date',
        'created_at',
        'updated_at',
    ];

    /**
     * Scope to filter by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category_slug', $category);
    }

    /**
     * Scope to filter by category title
     */
    public function scopeByCategoryTitle($query, string $categoryTitle)
    {
        return $query->where('category_title', $categoryTitle);
    }

    /**
     * Get organizations with website
     */
    public function scopeWithWebsite($query)
    {
        return $query->whereNotNull('website')->where('website', '!=', '');
    }

    /**
     * Get organizations with contact info
     */
    public function scopeWithContact($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('phone')
                ->orWhereNotNull('email')
                ->where('phone', '!=', '')
                ->where('email', '!=', '');
        });
    }

    /**
     * Get the category display name
     */
    public function getCategoryDisplayAttribute(): string
    {
        return $this->category_title ?? ucfirst(str_replace('-', ' ', $this->category_slug ?? 'Unknown'));
    }

    /**
     * Get formatted address
     */
    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->postcode,
            $this->city,
            $this->province,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if organization has complete contact information
     */
    public function hasCompleteContactInfo(): bool
    {
        return ! empty($this->website) || ! empty($this->phone) || ! empty($this->email);
    }

    /**
     * Get organizations by multiple categories
     */
    public static function getByCategories(array $categories): \Illuminate\Database\Eloquent\Collection
    {
        return static::whereIn('category_slug', $categories)->get();
    }

    /**
     * Get all available categories
     */
    public static function getAvailableCategories(): array
    {
        return static::distinct('category_title')
            ->whereNotNull('category_title')
            ->pluck('category_title')
            ->toArray();
    }

    /**
     * Get category statistics
     */
    public static function getCategoryStats(): array
    {
        return static::raw(function ($collection) {
            return $collection->aggregate([
                [
                    '$group' => [
                        '_id' => '$category_title',
                        'count' => ['$sum' => 1],
                        'with_website' => [
                            '$sum' => [
                                '$cond' => [
                                    ['$and' => [
                                        ['$ne' => ['$website', null]],
                                        ['$ne' => ['$website', '']],
                                    ]],
                                    1,
                                    0,
                                ],
                            ],
                        ],
                        'with_contact' => [
                            '$sum' => [
                                '$cond' => [
                                    ['$or' => [
                                        ['$and' => [['$ne' => ['$phone', null]], ['$ne' => ['$phone', '']]]],
                                        ['$and' => [['$ne' => ['$email', null]], ['$ne' => ['$email', '']]]],
                                    ]],
                                    1,
                                    0,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    '$sort' => ['count' => -1],
                ],
            ]);
        });
    }
}
