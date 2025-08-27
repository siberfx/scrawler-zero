<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperOrganizationAddress
 */
class OrganizationAddress extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'type',
        'straat',
        'huisnummer',
        'postbus',
        'postcode',
        'plaats',
    ];

    /**
     * Get the organization that owns the address.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include addresses of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get the formatted address.
     */
    public function getFormattedAddressAttribute(): string
    {
        if ($this->postbus) {
            return sprintf(
                "Postbus %s\n%s %s",
                $this->postbus,
                $this->postcode,
                $this->plaats
            );
        }

        return sprintf(
            "%s %s\n%s %s",
            $this->straat,
            $this->huisnummer,
            $this->postcode,
            $this->plaats
        );
    }
}
