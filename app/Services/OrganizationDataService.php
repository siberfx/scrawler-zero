<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;

class OrganizationDataService
{
    /**
     * Process organization data from JSON structure
     *
     * @param  array  $jsonData  The JSON data structure to process
     * @param  Organization|null  $organization  Optional existing organization to update
     * @return Organization The processed organization model
     */
    public function processOrganizationData(array $jsonData, ?Organization $organization = null): Organization
    {
        // Create a new organization if one wasn't provided
        $organization = $organization ?? new Organization;

        // Store the raw data
        $organization->details = $jsonData;

        // Extract structured data from the raw JSON
        $organization->extractStructuredData($jsonData);

        return $organization;
    }

    /**
     * Update an organization with new JSON data
     *
     * @param  Organization  $organization  The organization to update
     * @param  array  $jsonData  The new JSON data
     * @return Organization The updated organization
     */
    public function updateOrganization(Organization $organization, array $jsonData): Organization
    {
        try {
            // Process the data
            $this->processOrganizationData($jsonData, $organization);

            // Save the changes
            $organization->save();

            return $organization;
        } catch (\Exception $e) {
            Log::error("Error updating organization {$organization->id}: {$e->getMessage()}", [
                'exception' => $e,
                'organization_id' => $organization->id,
            ]);

            throw $e;
        }
    }

    /**
     * Process a batch of organizations with their JSON data
     *
     * @param  array  $organizationsData  Array of [organization_id => json_data] pairs
     * @return array Results of the batch processing
     */
    public function processBatch(array $organizationsData): array
    {
        $results = [
            'processed' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($organizationsData as $organizationId => $jsonData) {
            try {
                $organization = Organization::findOrFail($organizationId);
                $this->updateOrganization($organization, $jsonData);

                $results['processed']++;
                $results['details'][$organizationId] = [
                    'status' => 'success',
                    'message' => 'Organization updated successfully',
                ];
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][$organizationId] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Process a batch of organizations with their JSON data in a more dynamic format
     * Supports creating new organizations or updating existing ones based on provided data
     *
     * @param  array  $organizationsData  Array of organization data including id, name, url, type, and JSON data
     * @return array Results of the batch processing
     */
    public function processBatchOrganizations(array $organizationsData): array
    {
        $results = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($organizationsData as $index => $orgData) {
            try {
                // Check if organization exists by ID or create new one
                if (! empty($orgData['id'])) {
                    $organization = Organization::where('id', $orgData['id'])->first();

                    if ($organization) {
                        // Update existing organization
                        $organization->name = $orgData['name'];
                        $organization->url = $orgData['url'];
                        $organization->type = $orgData['type'];
                        $results['updated']++;
                    } else {
                        // Create new with specified ID
                        $organization = new Organization([
                            'id' => $orgData['id'],
                            'name' => $orgData['name'],
                            'url' => $orgData['url'],
                            'slug' => \Illuminate\Support\Str::slug($orgData['name']),
                            'type' => $orgData['type'],
                            'is_active' => true,
                        ]);
                        $results['created']++;
                    }
                } else {
                    // Create new organization
                    $organization = new Organization([
                        'name' => $orgData['name'],
                        'url' => $orgData['url'],
                        'slug' => \Illuminate\Support\Str::slug($orgData['name']),
                        'type' => $orgData['type'],
                        'is_active' => true,
                    ]);
                    $results['created']++;
                }

                // Store the raw data
                $organization->details = $orgData['data'];

                // Process the data using the service
                $this->processOrganizationData($orgData['data'], $organization);
                $organization->save();

                $results['processed']++;
                $results['details'][$index] = [
                    'status' => 'success',
                    'organization_id' => $organization->id,
                    'name' => $organization->name,
                ];
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][$index] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => $orgData,
                ];

                Log::error('Error processing organization in batch: '.$e->getMessage(), [
                    'exception' => $e,
                    'data' => $orgData,
                ]);
            }
        }

        return $results;
    }

    /**
     * Extract data from a specific field in the JSON structure
     *
     * @param  array  $data  The JSON data structure
     * @param  string  $path  Dot notation path to the desired field
     * @param  mixed  $default  Default value if the field doesn't exist
     * @return mixed The extracted value or default
     */
    public function extractField(array $data, string $path, $default = null)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (! isset($value[$key])) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Extract URL from a field that might be in different formats
     *
     * @param  array  $data  The JSON data structure
     * @param  string  $path  Dot notation path to the field
     * @return string|null The extracted URL or null
     */
    public function extractUrl(array $data, string $path): ?string
    {
        $value = $this->extractField($data, $path);

        if (empty($value)) {
            return null;
        }

        // If it's a string, assume it's already a URL
        if (is_string($value)) {
            return $value;
        }

        // Check if it's in the links[0].url format
        if (is_array($value) && isset($value['links'][0]['url'])) {
            return $value['links'][0]['url'];
        }

        // Check if it's in the text format (might contain a URL)
        if (is_array($value) && isset($value['text'])) {
            // Extract URL from text if it contains http:// or https://
            $text = $value['text'];
            if (preg_match('/https?:\/\/[^\s]+/', $text, $matches)) {
                return $matches[0];
            }

            // Return the text as is (might be processed further)
            return $text;
        }

        return null;
    }

    /**
     * Ensure a URL is absolute by adding the base URL if necessary
     *
     * @param  string  $url  The URL to check
     * @param  string  $baseUrl  The base URL to prepend if needed
     * @return string|null The absolute URL or null
     */
    public function ensureAbsoluteUrl(?string $url, string $baseUrl = 'https://organisaties.overheid.nl'): ?string
    {
        if (empty($url)) {
            return null;
        }

        // If the URL starts with a slash, it's a relative URL
        if (str_starts_with($url, '/')) {
            return $baseUrl.$url;
        }

        return $url;
    }

    public static function generateAcronym(string $name): string
    {
        if (empty($name)) {
            return '';
        }

        // Remove common prefixes like 'Gemeente', 'Ministerie van', etc.
        // also trim () characters
        $cleanName = preg_replace('/^(Gemeente|Ministerie van|Provincie)\s+/i', '', $name);
        $cleanName = preg_replace('/\s*\(.*\)\s*/', '', $cleanName);

        // Split the name into words
        $words = preg_split('/\s+/', $cleanName);

        // If we have multiple words, take first letter of each word
        if (count($words) > 1) {
            $acronym = '';
            foreach ($words as $word) {
                if (! empty($word)) {
                    $acronym .= mb_strtoupper(mb_substr($word, 0, 1));
                }
            }

            return $acronym;
        }

        // If it's a single word, take the first 3-4 letters
        return mb_strtoupper(mb_substr($cleanName, 0, min(4, mb_strlen($cleanName))));
    }
}
