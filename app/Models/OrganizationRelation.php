<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperOrganizationRelation
 */
class OrganizationRelation extends Model
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
        'naam',
        'relatie_type',
    ];

    /**
     * Get the organization that owns the relation.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include relations of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to filter by relation type.
     */
    public function scopeByRelatieType($query, $relatieType)
    {
        return $query->where('relatie_type', $relatieType);
    }
}
