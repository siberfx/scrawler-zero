<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperOrganizationDomain
 */
class OrganizationDomain extends Model
{
    use Prunable;

    protected $connection = 'mongodb';
    protected $table = 'organization_domains';

    protected $fillable = [
        'organization_id',
        'uri',
        'status',
        'last_checked_at',
        'created_at',
        'updated_at',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Prune old records, keeping only the 10 most recent per organization_id.
     */
    public function prunable()
    {
        // Subquery: select ids to keep (latest 10 per organization)
        $keepIds = static::selectRaw('MAX(id) as id')
            ->whereNotNull('organization_id')
            ->groupBy('organization_id')
            ->get()
            ->flatMap(function ($row) {
                $orgId = $row->id;
                return static::where('organization_id', $orgId)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->pluck('id');
            })->unique();

        // Prune all except those in $keepIds
        return static::whereNotNull('organization_id')
            ->whereNotIn('id', $keepIds);
    }
}
