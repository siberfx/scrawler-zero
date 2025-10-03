<?php

namespace App\Commands;

use App\Models\PidData;
use App\Models\PidOrganization;
use App\Models\PidDossier;
use App\Models\PidDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FetchPidDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pid:fetch 
                            {--pid=nl.mnre1058 : The PID identifier to fetch} 
                            {--infobox=true : Include infobox data}
                            {--save-to-file : Save the JSON data to a file}
                            {--save-to-db : Save the data to the database}
                            {--save-relational : Save data in relational structure (organization, dossiers, documents)}
                            {--examine : Examine and display the JSON structure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and examine JSON data from pid.wooverheid.nl';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pid = $this->option('pid');
        $infobox = $this->option('infobox') ? 'true' : 'false';
        $saveToFile = $this->option('save-to-file');
        $saveToDb = $this->option('save-to-db');
        $saveRelational = $this->option('save-relational');
        $examine = $this->option('examine');

        $url = "https://pid.wooverheid.nl/?pid={$pid}&infobox={$infobox}";
        
        $this->info("Fetching data from: {$url}");

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9,nl;q=0.8',
            ])->timeout(30)->get($url);

            if (!$response->successful()) {
                $this->error("HTTP request failed with status: {$response->status()}");
                return 1;
            }

            $jsonData = $response->body();
            $decodedData = json_decode($jsonData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON response: ' . json_last_error_msg());
                return 1;
            }

            $this->info('✓ Successfully fetched JSON data');
            $this->info('Data size: ' . $this->formatBytes(strlen($jsonData)));

            // Save to file if requested
            if ($saveToFile) {
                $filename = "pid_data_{$pid}_" . date('Y-m-d_H-i-s') . '.json';
                Storage::disk('local')->put($filename, $jsonData);
                $this->info("✓ Data saved to: storage/app/{$filename}");
            }

            // Save to database if requested
            if ($saveToDb) {
                $this->savePidDataToDatabase($pid, $url, $decodedData, strlen($jsonData));
            }

            // Save in relational structure if requested
            if ($saveRelational) {
                $this->saveRelationalData($pid, $url, $decodedData);
            }

            // Link with existing organization by PID
            $this->linkWithExistingOrganization($pid, $decodedData);

            // Examine the data structure if requested
            if ($examine) {
                $this->examineJsonStructure($decodedData);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
            Log::error('FetchPidDataCommand Error', ['exception' => $e]);
            return 1;
        }
    }

    /**
     * Examine and display the JSON data structure
     */
    protected function examineJsonStructure(array $data): void
    {
        $this->newLine();
        $this->line('<fg=cyan>📊 JSON Data Structure Analysis</fg=cyan>');
        $this->line(str_repeat('=', 50));

        // Basic info
        if (isset($data['resource'])) {
            $this->info("Resource ID: {$data['resource']}");
        }

        // Infobox analysis
        if (isset($data['infobox'])) {
            $infobox = $data['infobox'];
            $this->newLine();
            $this->line('<fg=yellow>📋 Infobox Summary:</fg=yellow>');
            
            if (isset($infobox['foi_totalDossiers'])) {
                $this->info("Total Dossiers: " . number_format($infobox['foi_totalDossiers']));
            }

            if (isset($infobox['foi_dossiers']) && is_array($infobox['foi_dossiers'])) {
                $dossiers = $infobox['foi_dossiers'];
                $this->info("Dossiers in response: " . count($dossiers));

                // Analyze first dossier
                if (!empty($dossiers)) {
                    $firstDossier = $dossiers[0];
                    $this->newLine();
                    $this->line('<fg=green>📁 First Dossier Details:</fg=green>');
                    
                    $this->displayDossierInfo($firstDossier);

                    // Analyze document types
                    $this->analyzeDossierTypes($dossiers);
                }
            }
        }

        $this->newLine();
        $this->line('<fg=magenta>🔍 Raw JSON Preview (first 500 chars):</fg=magenta>');
        $jsonPreview = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->line(substr($jsonPreview, 0, 500) . (strlen($jsonPreview) > 500 ? '...' : ''));
    }

    /**
     * Display information about a single dossier
     */
    protected function displayDossierInfo(array $dossier): void
    {
        $fields = [
            'dc_identifier' => 'Identifier',
            'dc_title' => 'Title',
            'dc_type' => 'Type',
            'dc_description' => 'Description',
            'foi_nrDocuments' => 'Number of Documents',
            'foi_retrievedDate' => 'Retrieved Date',
            'foi_publishedDate' => 'Published Date',
            'dc_date_year' => 'Year',
            'foi_startDate' => 'Start Date',
            'foi_updateDate' => 'Update Date',
            'dc_type_description' => 'Type Description',
            'foi_nrPagesInDossier' => 'Pages in Dossier',
        ];

        foreach ($fields as $key => $label) {
            if (isset($dossier[$key])) {
                $value = $dossier[$key];
                if (is_numeric($value)) {
                    $value = number_format($value);
                }
                $this->line("  • {$label}: {$value}");
            }
        }

        // Files analysis
        if (isset($dossier['foi_files']) && is_array($dossier['foi_files'])) {
            $files = $dossier['foi_files'];
            $this->line("  • Files: " . count($files) . " files");
            
            if (!empty($files)) {
                $this->line("  • Sample files:");
                $sampleFiles = array_slice($files, 0, 3);
                foreach ($sampleFiles as $file) {
                    $title = $file['dc_title'] ?? 'Unknown';
                    $type = $file['dc_type'] ?? 'Unknown';
                    $format = $file['dc_format'] ?? 'Unknown';
                    $this->line("    - {$title} ({$type}, {$format})");
                }
                if (count($files) > 3) {
                    $this->line("    ... and " . (count($files) - 3) . " more files");
                }
            }
        }
    }

    /**
     * Analyze document types across all dossiers
     */
    protected function analyzeDossierTypes(array $dossiers): void
    {
        $this->newLine();
        $this->line('<fg=blue>📊 Document Type Analysis:</fg=blue>');

        $typeStats = [];
        $yearStats = [];
        $totalDocuments = 0;

        foreach ($dossiers as $dossier) {
            // Type statistics
            $type = $dossier['dc_type_description'] ?? $dossier['dc_type'] ?? 'Unknown';
            $typeStats[$type] = ($typeStats[$type] ?? 0) + 1;

            // Year statistics
            if (isset($dossier['dc_date_year'])) {
                $year = $dossier['dc_date_year'];
                $yearStats[$year] = ($yearStats[$year] ?? 0) + 1;
            }

            // Document count
            if (isset($dossier['foi_nrDocuments'])) {
                $totalDocuments += $dossier['foi_nrDocuments'];
            }
        }

        // Display type statistics
        $this->line("Document Types:");
        arsort($typeStats);
        foreach (array_slice($typeStats, 0, 5, true) as $type => $count) {
            $this->line("  • {$type}: {$count}");
        }

        // Display year range
        if (!empty($yearStats)) {
            $minYear = min(array_keys($yearStats));
            $maxYear = max(array_keys($yearStats));
            $this->line("Year Range: {$minYear} - {$maxYear}");
        }

        $this->line("Total Documents: " . number_format($totalDocuments));
    }

    /**
     * Format bytes into human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Save PID data to the database
     */
    protected function savePidDataToDatabase(string $pid, string $url, array $data, int $dataSize): void
    {
        try {
            // Extract metadata for easier querying
            $metadata = [];
            $totalDossiers = $data['infobox']['foi_totalDossiers'] ?? 0;
            $dossiers = $data['infobox']['foi_dossiers'] ?? [];
            
            // Calculate document type statistics
            $documentTypes = [];
            $years = [];
            $totalDocuments = 0;
            
            foreach ($dossiers as $dossier) {
                $type = $dossier['dc_type_description'] ?? $dossier['dc_type'] ?? 'Unknown';
                $documentTypes[$type] = ($documentTypes[$type] ?? 0) + 1;
                
                if (isset($dossier['dc_date_year'])) {
                    $years[] = $dossier['dc_date_year'];
                }
                
                $totalDocuments += $dossier['foi_nrDocuments'] ?? 0;
            }
            
            $metadata['document_types'] = $documentTypes;
            $metadata['total_documents'] = $totalDocuments;
            
            if (!empty($years)) {
                $metadata['year_range'] = [
                    'min' => min($years),
                    'max' => max($years)
                ];
            }

            // Check if record already exists
            $existingRecord = PidData::where('resource_id', $pid)->first();
            
            if ($existingRecord) {
                // Update existing record (without storing full raw_data due to size limits)
                $existingRecord->update([
                    'pid' => $pid, // Store the PID for filtering
                    'pid_url' => $url,
                    'total_dossiers' => $totalDossiers,
                    'dossiers_count' => count($dossiers),
                    'metadata' => $metadata,
                    'fetch_timestamp' => now(),
                    'data_size' => $dataSize,
                    'is_processed' => true,
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
                
                $this->info("✓ Updated existing PID data record for: {$pid}");
            } else {
                // Create new record (without storing full raw_data due to size limits)
                PidData::create([
                    'resource_id' => $pid,
                    'pid' => $pid, // Store the PID for filtering
                    'pid_url' => $url,
                    'total_dossiers' => $totalDossiers,
                    'dossiers_count' => count($dossiers),
                    'metadata' => $metadata,
                    'fetch_timestamp' => now(),
                    'data_size' => $dataSize,
                    'is_processed' => true,
                    'processed_at' => now(),
                ]);
                
                $this->info("✓ Created new PID data record for: {$pid}");
            }
            
            $this->info("   - Total dossiers: " . number_format($totalDossiers));
            $this->info("   - Dossiers in response: " . number_format(count($dossiers)));
            $this->info("   - Total documents: " . number_format($totalDocuments));
            
        } catch (\Exception $e) {
            $this->error("Failed to save to database: {$e->getMessage()}");
            Log::error('FetchPidDataCommand Database Error', [
                'pid' => $pid,
                'exception' => $e
            ]);
        }
    }

    /**
     * Save PID data in relational structure (organization -> dossiers -> documents)
     */
    protected function saveRelationalData(string $pid, string $url, array $data): void
    {
        try {
            $this->info('📊 Saving data in relational structure...');
            
            $infobox = $data['infobox'] ?? [];
            $totalDossiers = $infobox['foi_totalDossiers'] ?? 0;
            $dossiers = $infobox['foi_dossiers'] ?? [];
            
            // Calculate organization-level statistics
            $documentTypes = [];
            $years = [];
            $totalDocuments = 0;
            
            foreach ($dossiers as $dossier) {
                $type = $dossier['dc_type_description'] ?? $dossier['dc_type'] ?? 'Unknown';
                $documentTypes[$type] = ($documentTypes[$type] ?? 0) + 1;
                
                if (isset($dossier['dc_date_year'])) {
                    $years[] = $dossier['dc_date_year'];
                }
                
                $totalDocuments += $dossier['foi_nrDocuments'] ?? 0;
            }
            
            $metadata = [
                'document_types' => $documentTypes,
                'total_documents' => $totalDocuments,
            ];
            
            if (!empty($years)) {
                $metadata['year_range'] = [
                    'min' => min($years),
                    'max' => max($years)
                ];
            }

            // 1. Create or update organization
            $organization = PidOrganization::updateOrCreate(
                ['resource_id' => $pid],
                [
                    'pid_url' => $url,
                    'name' => $this->extractOrganizationName($pid),
                    'total_dossiers' => $totalDossiers,
                    'total_documents' => $totalDocuments,
                    'fetch_timestamp' => now(),
                    'last_updated' => now(),
                    'is_active' => true,
                    'metadata' => $metadata,
                ]
            );

            $this->info("✓ Organization: {$organization->display_name} (ID: {$organization->id})");
            
            $dossiersCreated = 0;
            $dossiersUpdated = 0;
            $documentsCreated = 0;

            // 2. Process each dossier
            foreach ($dossiers as $dossierData) {
                $dossier = PidDossier::updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'dc_identifier' => $dossierData['dc_identifier']
                    ],
                    [
                        'dc_title' => $dossierData['dc_title'] ?? null,
                        'dc_type' => $dossierData['dc_type'] ?? null,
                        'dc_type_description' => $dossierData['dc_type_description'] ?? null,
                        'dc_description' => $dossierData['dc_description'] ?? null,
                        'dc_source' => $dossierData['dc_source'] ?? null,
                        'dc_publisher' => $dossierData['dc_publisher'] ?? null,
                        'dc_creator' => $dossierData['dc_creator'] ?? null,
                        'dc_date_year' => $dossierData['dc_date_year'] ?? null,
                        'foi_start_date' => isset($dossierData['foi_startDate']) ? 
                            \Carbon\Carbon::parse($dossierData['foi_startDate']) : null,
                        'foi_update_date' => isset($dossierData['foi_updateDate']) ? 
                            \Carbon\Carbon::parse($dossierData['foi_updateDate']) : null,
                        'foi_retrieved_date' => isset($dossierData['foi_retrievedDate']) ? 
                            \Carbon\Carbon::parse($dossierData['foi_retrievedDate']) : null,
                        'foi_published_date' => isset($dossierData['foi_publishedDate']) ? 
                            \Carbon\Carbon::parse($dossierData['foi_publishedDate']) : null,
                        'foi_nr_documents' => $dossierData['foi_nrDocuments'] ?? 0,
                        'foi_nr_pages_in_dossier' => $dossierData['foi_nrPagesInDossier'] ?? 0,
                        'foi_fairiscore_versions' => $dossierData['foi_fairiscoreVersions'] ?? null,
                    ]
                );

                if ($dossier->wasRecentlyCreated) {
                    $dossiersCreated++;
                } else {
                    $dossiersUpdated++;
                }

                // 3. Process documents in this dossier
                $files = $dossierData['foi_files'] ?? [];
                foreach ($files as $fileData) {
                    $document = PidDocument::updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'dossier_id' => $dossier->id,
                            'dc_identifier' => $fileData['dc_identifier']
                        ],
                        [
                            'dc_title' => $fileData['dc_title'] ?? null,
                            'dc_type' => $fileData['dc_type'] ?? null,
                            'dc_source' => $fileData['dc_source'] ?? null,
                            'dc_format' => $fileData['dc_format'] ?? null,
                            'foi_file_name' => $fileData['foi_fileName'] ?? null,
                            'is_downloaded' => false,
                            'download_url' => $fileData['dc_source'] ?? null,
                        ]
                    );

                    if ($document->wasRecentlyCreated) {
                        $documentsCreated++;
                    }
                }
            }

            $this->info("✓ Dossiers: {$dossiersCreated} created, {$dossiersUpdated} updated");
            $this->info("✓ Documents: {$documentsCreated} created");
            $this->info("📈 Total in database:");
            $this->info("   - Organization: 1");
            $this->info("   - Dossiers: " . number_format($organization->dossiers()->count()));
            $this->info("   - Documents: " . number_format($organization->documents()->count()));

        } catch (\Exception $e) {
            $this->error("Failed to save relational data: {$e->getMessage()}");
            Log::error('FetchPidDataCommand Relational Save Error', [
                'pid' => $pid,
                'exception' => $e
            ]);
        }
    }

    /**
     * Extract organization name from PID or use a mapping
     */
    protected function extractOrganizationName(string $pid): string
    {
        // Map known PIDs to organization names
        $organizationMap = [
            'nl.mnre1058' => 'Ministerie van Justitie en Veiligheid',
            // Add more mappings as needed
        ];

        return $organizationMap[$pid] ?? $pid;
    }

    /**
     * Link PID data with existing organization in the database
     */
    protected function linkWithExistingOrganization(string $pid, array $data): void
    {
        try {
            // Import Organization model
            $organization = \App\Models\Organization::where('pid', $pid)->first();
            
            if ($organization) {
                $this->info("✓ Found existing organization: {$organization->name}");
                
                // Calculate summary statistics from PID data
                $infobox = $data['infobox'] ?? [];
                $totalDossiers = $infobox['foi_totalDossiers'] ?? 0;
                $dossiers = $infobox['foi_dossiers'] ?? [];
                
                $totalDocuments = 0;
                foreach ($dossiers as $dossier) {
                    $totalDocuments += $dossier['foi_nrDocuments'] ?? 0;
                }
                
                // Update organization with PID-related metadata
                $pidMetadata = $organization->raw_data ?? [];
                $pidMetadata['pid_data'] = [
                    'total_dossiers' => $totalDossiers,
                    'dossiers_in_response' => count($dossiers),
                    'total_documents' => $totalDocuments,
                    'last_pid_fetch' => now()->toIso8601String(),
                    'pid_url' => "https://pid.wooverheid.nl/?pid={$pid}&infobox=true",
                ];
                
                $organization->update([
                    'raw_data' => $pidMetadata,
                ]);
                
                $this->info("   - Updated organization with PID metadata");
                $this->info("   - Total dossiers: " . number_format($totalDossiers));
                $this->info("   - Total documents: " . number_format($totalDocuments));
            } else {
                $this->warn("⚠ No existing organization found with PID: {$pid}");
                $this->info("   Consider running the organizations:crawl command first to populate organizations");
            }
            
        } catch (\Exception $e) {
            $this->error("Failed to link with existing organization: {$e->getMessage()}");
            Log::error('FetchPidDataCommand Link Error', [
                'pid' => $pid,
                'exception' => $e
            ]);
        }
    }
}
