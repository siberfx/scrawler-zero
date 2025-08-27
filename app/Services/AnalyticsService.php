<?php

namespace App\Services;

use App\Enums\AnalyticsStatType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class AnalyticsService
{
    private string $apiKey;

    private string $baseUri;

    // Use a constant for the default website ID to avoid "magic numbers"
    private const DEFAULT_WEBSITE_ID = 1;

    public function __construct()
    {
        $this->apiKey = config('services.analytics.api_key');
        $this->baseUri = config('services.analytics.base_uri');

        if (! $this->apiKey || ! $this->baseUri) {
            throw new InvalidArgumentException('Analytics API key or Base URI is not configured in config/services.php.');
        }
    }

    /**
     * Fetch statistics from the analytics API.
     *
     * @param  AnalyticsStatType  $name  The type of stat to fetch.
     * @param  int  $websiteId  The website identifier.
     * @param  string|null  $from  The start date in Y-m-d format.
     * @param  string|null  $to  The end date in Y-m-d format.
     * @param  string|null  $search  Optional search query.
     * @param  string|null  $searchBy  Optional search field.
     * @param  string|null  $sortBy  Sort results by 'count' or 'value'.
     * @param  string|null  $sort  Sort direction 'asc' or 'desc'.
     * @param  int|null  $perPage  Results per page.
     */
    public function getStats(
        AnalyticsStatType $name,
        int $websiteId = self::DEFAULT_WEBSITE_ID,
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
        ?string $searchBy = null,
        ?string $sortBy = null,
        ?string $sort = null,
        ?int $perPage = null
    ): Response {
        $url = "{$this->baseUri}/stats/{$websiteId}";

        $queryParams = [
            'name' => $name->value,
            'from' => $from ?? now()->subMonth()->format('Y-m-d'),
            'to' => $to ?? now()->format('Y-m-d'),
            'search' => $search,
            'search_by' => $searchBy,
            'sort_by' => $sortBy,
            'sort' => $sort,
            'per_page' => $perPage,
        ];

        return $this->buildRequest()->get($url, array_filter($queryParams));
    }

    /**
     * Helper to build the base HTTP request with headers.
     */
    private function buildRequest(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);
    }
}
