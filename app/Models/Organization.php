<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Helpers\Variable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @mixin IdeHelperOrganization
 */
class Organization extends Model
{
    use SoftDeletes;

    protected $connection = 'mongodb';

    protected $fillable = [
        'name',
        'abbreviation',
        'url',
        'slug',
        'type',
        'organization_category_id',
        'is_active',
        'raw_data',
        'details',
        'details_processed',
        'last_processed_at',
        // PID identifier for filtering and linking
        'pid',
        // Organization details (organisatiegegevens)
        'visit_address',
        'postal_address',
        'organogram_url',
        'organization_details_url',
        // Organization description (beschrijving)
        'description',
        'description_url',
        // Contact details (contactgegevens)
        'phone',
        'website',
        'email',
        // WOO request details (indienen_woo_verzoek)
        'woo_request_address',
        'woo_digital_request_url',
        'woo_info_url',
        // Organization functions (functies_organisatie)
        'woo_contact_phone',
        'woo_contact_email',

        'organization_id',
        'province',

    ];

    protected function casts()
    {
        return [
            'type' => OrganizationType::class,
            'is_active' => 'boolean',
            'raw_data' => 'array',
            'details' => 'array',
            'details_processed' => 'boolean',
            'last_processed_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the addresses for the organization.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrganizationAddress::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class, 'organization_id', 'id');
    }

    /**
     * Get the relations for the organization.
     */
    public function relations(): HasMany
    {
        return $this->hasMany(OrganizationRelation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OrganizationCategory::class, 'organization_category_id', 'id');
    }

    public function toSearchableArray(): array
    {
        $array = [
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'type' => (string) $this->type->value,
            'type_label' => $this->type->label(),
            'is_active' => $this->is_active ? 1 : 0,
            'details_processed' => $this->details_processed ? 1 : 0,
            'created_at' => $this->created_at?->timestamp ?? time(),
            'category_id' => $this->category_id,
            'category' => $this->category?->name ?? '-',
            'pid' => $this->pid ? (string) $this->pid : '',
            // Include structured fields - ensure all are strings
            'visit_address' => $this->visit_address ? (string) $this->visit_address : '',
            'postal_address' => $this->postal_address ? (string) $this->postal_address : '',
            'description' => $this->description ? (string) $this->description : '',
            'phone' => $this->phone ? (string) $this->phone : '',
            'website' => $this->website ? (string) $this->website : '',
            'email' => $this->email ? (string) $this->email : '',
            'woo_request_address' => $this->woo_request_address ? (string) $this->woo_request_address : '',
            'woo_digital_request_url' => $this->woo_digital_request_url ? (string) $this->woo_digital_request_url : '',
            'woo_info_url' => $this->woo_info_url ? (string) $this->woo_info_url : '',
            'woo_contact_phone' => $this->woo_contact_phone ? (string) $this->woo_contact_phone : '',
            'woo_contact_email' => $this->woo_contact_email ? (string) $this->woo_contact_email : '',
            'organogram_url' => $this->organogram_url ? (string) $this->organogram_url : '',
            'organization_details_url' => $this->organization_details_url ? (string) $this->organization_details_url : '',
            'description_url' => $this->description_url ? (string) $this->description_url : '',
            'address_street' => $this->address_street ? (string) $this->address_street : '',
            'address_postal_code' => $this->address_postal_code ? (string) $this->address_postal_code : '',
            'address_city' => $this->address_city ? (string) $this->address_city : '',
            'contact_telefoon' => $this->contact_telefoon ? (string) $this->contact_telefoon : '',
            'contact_email' => $this->contact_email ? (string) $this->contact_email : '',
            'province' => $this->province ?? '-',
        ];

        // Extract specific data from contactgegevens for backward compatibility
        // but do not include the raw JSON data
        if ($this->details && is_array($this->details)) {
            // Specific extraction for 'beschrijving' if not already set
            if (empty($array['description']) && isset($this->details['beschrijving']['text']) && is_array($this->details['beschrijving']['text'])) {
                $array['description'] = implode(' ', $this->details['beschrijving']['text']);
            } elseif (empty($array['description']) && isset($this->details['beschrijving']) && is_string($this->details['beschrijving'])) {
                $array['description'] = $this->details['beschrijving'];
            }

            // Specific extraction for 'contactgegevens' - only extract simple values
            if (isset($this->details['contactgegevens']['table_data']) && is_array($this->details['contactgegevens']['table_data'])) {
                foreach ($this->details['contactgegevens']['table_data'] as $key => $value) {
                    if (is_string($value)) {
                        $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key); // Sanitize key
                        $array['contact_'.$cleanKey] = $value;
                    }
                }
            }
        }

        $addresses = $this->addresses;
        if ($addresses->isNotEmpty()) {
            $addressData = [];
            foreach ($addresses as $address) {
                $addressData[] = [
                    'type' => $address->type,
                    'street' => $address->street,
                    'house_number' => $address->house_number,
                    'postal_code' => $address->postal_code,
                    'city' => $address->city,
                ];

                // Add the first address directly to the search index
                if (count($addressData) === 1) {
                    $array['address_street'] = $address->street;
                    $array['address_postal_code'] = $address->postal_code;
                    $array['address_city'] = $address->city;
                }
            }
            $array['addresses'] = $addressData;
            $array['province'] = (string) $this->province ?? 'Unknown';
        }

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
        return 'organizations';
    }

    /**
     * Determine if the model should be searchable.
     *
     * @return bool
     */
    public function shouldBeSearchable()
    {
        return true;
    }

    /**
     * Extract structured data from details JSON and populate the model's attributes
     *
     * @param  array  $jsonData  The details JSON data to process
     * @return $this
     */
    public function extractStructuredData(?array $jsonData = null)
    {
        // Use provided JSON data or fall back to existing details
        $data = $jsonData ?? $this->details;

        if (empty($data)) {
            return $this;
        }

        // Extract organization details (organisatiegegevens)
        if (isset($data['organisatiegegevens']['table_data'])) {
            $orgDetails = $data['organisatiegegevens']['table_data'];

            $this->visit_address = $orgDetails['bezoekadres'] ?? null;
            $this->postal_address = $orgDetails['postadres'] ?? null;

            $this->province = Variable::getCityFromDutchAddress($this->woo_request_address);

            // Extract organogram URL
            if (isset($orgDetails['organogram']['links'][0]['url'])) {
                $this->organogram_url = $this->ensureAbsoluteUrl($orgDetails['organogram']['links'][0]['url']);
            }

            // Extract organization details URL
            if (isset($orgDetails['alle_organisatiegegevens']['links'][0]['url'])) {
                $this->organization_details_url = $this->ensureAbsoluteUrl($orgDetails['alle_organisatiegegevens']['links'][0]['url']);
            }
        }

        // Extract organization description (beschrijving)
        if (isset($data['beschrijving'])) {
            // Combine text array into a single description
            if (isset($data['beschrijving']['text']) && is_array($data['beschrijving']['text'])) {
                $this->description = implode("\n", array_filter($data['beschrijving']['text']));
            }

            // Extract description URL
            if (isset($data['beschrijving']['links'][0]['url'])) {
                $this->description_url = $this->ensureAbsoluteUrl($data['beschrijving']['links'][0]['url']);
            }
        }

        // Extract contact details (contactgegevens)
        if (isset($data['contactgegevens']['table_data'])) {
            $contactDetails = $data['contactgegevens']['table_data'];

            $this->phone = $contactDetails['telefoon'] ?? null;

            // Extract website URL
            if (isset($contactDetails['internet']['links'][0]['url'])) {
                $this->website = $contactDetails['internet']['links'][0]['url'];
            } elseif (isset($contactDetails['internet']['text'])) {
                // Extract URL from text if links array is not available
                $this->website = preg_replace('/\s*\(.*\)$/', '', $contactDetails['internet']['text']);
            }

            // Extract email
            if (isset($contactDetails['e_mail']['links'][0]['url'])) {
                $this->email = str_replace('mailto:', '', $contactDetails['e_mail']['links'][0]['url']);
            } elseif (isset($contactDetails['e_mail']['text'])) {
                // Extract email from text if links array is not available
                $this->email = preg_replace('/\s*\(.*\)$/', '', $contactDetails['e_mail']['text']);
            }
        }

        // Extract WOO request details (indienen_woo_verzoek)
        if (isset($data['indienen_woo_verzoek']['table_data'])) {
            $wooDetails = $data['indienen_woo_verzoek']['table_data'];

            $this->woo_request_address = $wooDetails['adres_voor_indienen_woo_verzoek'] ?? null;

            // Extract digital WOO request URL
            if (isset($wooDetails['digitaal_indienen_woo_verzoek']['links'][0]['url'])) {
                $this->woo_digital_request_url = $wooDetails['digitaal_indienen_woo_verzoek']['links'][0]['url'];
            }

            // Extract WOO info URL
            if (isset($wooDetails['link_naar_meer_informatie']['links'][0]['url'])) {
                $this->woo_info_url = $wooDetails['link_naar_meer_informatie']['links'][0]['url'];
            }
        }

        // Extract organization functions (functies_organisatie)
        if (isset($data['functies_organisatie']['table_data'])) {
            $functionsDetails = $data['functies_organisatie']['table_data'];

            $this->woo_contact_phone = $functionsDetails['telefoon_woo_contactpersoon'] ?? null;

            // Extract WOO contact email
            if (isset($functionsDetails['e_mail_woo_contactpersoon']['links'][0]['url'])) {
                $this->woo_contact_email = str_replace('mailto:', '', $functionsDetails['e_mail_woo_contactpersoon']['links'][0]['url']);
            } elseif (isset($functionsDetails['e_mail_woo_contactpersoon']['text'])) {
                // Extract email from text if links array is not available
                $this->woo_contact_email = preg_replace('/\s*\(.*\)$/', '', $functionsDetails['e_mail_woo_contactpersoon']['text']);
            }
        }

        // Mark as processed
        $this->details_processed = true;
        $this->last_processed_at = now();

        return $this;
    }

    /**
     * Ensure a URL is absolute by adding the base URL if necessary
     *
     * @param  string  $url  The URL to check
     * @return string The absolute URL
     */
    protected function ensureAbsoluteUrl($url)
    {
        if (empty($url)) {
            return null;
        }

        // If the URL starts with a slash, it's a relative URL
        if (str_starts_with($url, '/')) {
            return 'https://organisaties.overheid.nl'.$url;
        }

        return $url;
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
        return [
            'name',
            'slug',
            'type',
            'type_label',
            'category',
            'pid',
            'visit_address',
            'postal_address',
            'phone',
            'email',
            'website',
            'woo_info_url',
            'woo_contact_email', // Changed from woo_contact_info to woo_contact_email which exists in the schema
        ];
    }

    /**
     * Scope a query to filter by organization type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to search by name or abbreviation.
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('name', 'like', "%{$search}%")
                ->orWhere('abbreviation', 'like', "%{$search}%");
        }

        return $query;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    // We'll use the category relationship directly instead of an accessor
}
