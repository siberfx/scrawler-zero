<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @mixin IdeHelperOrganizationCategory
 */
class OrganizationCategory extends Model
{

    public const string CACHE_KEY = 'organization_categories';

    protected $connection = 'mongodb';
    protected $table = 'organization_categories';

    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    public function organizations(): OrganizationCategory|HasMany
    {
        return $this->hasMany(Organization::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(static fn() => Cache::forget(self::CACHE_KEY));
        static::updated(static fn() => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn() => Cache::forget(self::CACHE_KEY));
    }

    public static function getCached()
    {

        if (!Cache::has(self::CACHE_KEY)) {
            return Cache::remember(self::CACHE_KEY, 60 * 60, function () {
                return self::query()->get();
            });
        }

        return Cache::get(self::CACHE_KEY);
    }
}
