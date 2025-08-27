<?php

namespace App\Commands;

use App\Models\Document;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Console\Scheduling\Schedule;

class ProcessDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:process 
                            {--limit=100 : Maximum number of documents to process} 
                            {--timeout=30 : HTTP request timeout in seconds}
                            {--force : Force reprocessing of already processed documents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process unprocessed documents by fetching their content and extracting metadata';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting document processing...');

        $limit = (int) $this->option('limit');
        $timeout = (int) $this->option('timeout');
        $force = $this->option('force');

        // Get unprocessed documents
        $query = Document::query();
        
        if ($force) {
            $this->info('Force mode enabled - processing all documents');
        } else {
            $query->where('is_processed', false);
        }

        $documents = $query->whereNotNull('source_url')
            ->limit($limit)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No unprocessed documents found.');
            return 0;
        }

        $this->info("Found {$documents->count()} documents to process.");

        $processedCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        $progressBar = $this->output->createProgressBar($documents->count());
        $progressBar->start();

        foreach ($documents as $document) {
            try {
                $this->processDocument($document, $timeout);
                $processedCount++;
            } catch (\Exception $e) {
                $this->handleDocumentError($document, $e);
                $errorCount++;
            }

            $progressBar->advance();
            
            // Small delay to be respectful to the server
            usleep(500000); // 0.5 seconds
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Processing completed!");
        $this->info("Processed: {$processedCount}");
        $this->info("Errors: {$errorCount}");
        $this->info("Skipped: {$skippedCount}");

        return 0;
    }

    /**
     * Process a single document by fetching its content and extracting metadata.
     */
    protected function processDocument(Document $document, int $timeout): void
    {
        $this->line("Processing: {$document->title}");

        // Fetch the document content
        $response = Http::timeout($timeout)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'nl,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
            ])
            ->get($document->source_url);

        // Update basic response information
        $updateData = [
            'status_code' => $response->status(),
            'content_type_header' => $response->header('Content-Type'),
            'last_checked_timestamp' => now(),
            'error_message' => null,
        ];

        if ($response->successful()) {
            $content = $response->body();
            $contentHash = hash('sha256', $content);

            // Check if content has changed
            if ($document->content_hash !== $contentHash) {
                $updateData['content_hash'] = $contentHash;
                
                // Extract metadata from the content
                $metadata = $this->extractMetadata($content, $document->source_url);
                $updateData = array_merge($updateData, $metadata);
                
                // Apply mapped database fields from structured data
                $this->applyMappedFieldsToUpdateData($metadata, $updateData);

                // Store the document file if it's a downloadable file
                if ($this->isDownloadableFile($response)) {
                    $filePath = $this->storeDocumentFile($content, $document, $response);
                    if ($filePath) {
                        $updateData['archived_file_path'] = $filePath;
                        $updateData['file_size'] = strlen($content);
                        $updateData['checksum'] = hash('md5', $content);
                    }
                }

                // Try to download linked PDF documents
                $this->downloadLinkedDocuments($metadata, $document, $updateData);
            }

            $updateData['is_processed'] = true;
            $updateData['processed_at'] = now();
        } else {
            $updateData['error_message'] = "HTTP {$response->status()}: Failed to fetch document";
        }

        // Log the extracted data as JSON to Laravel log
        Log::info('Document Processing Results', [
            'document_id' => $document->id,
            'url' => $document->source_url,
            'extracted_data' => $updateData,
            'processing_timestamp' => now()->toISOString(),
            'extraction_method' => 'DOMDocument_XPath'
        ]);

        // Update the document without syncing to search index to avoid Typesense errors
        // This is necessary because the search index may not be able to handle the updated data
        Document::withoutSyncingToSearch(function () use ($document, $updateData) {
            $document->update($updateData);
        });
    }

    /**
     * Extract metadata from document content using DOMDocument and XPath (same approach as CrawlOrganisationsCommand).
     */
    protected function extractMetadata(string $content, string $url): array
    {
        $metadata = [];

        // Create a new DOM document (same approach as CrawlOrganisationsCommand)
        $dom = new DOMDocument;

        // Suppress warnings for malformed HTML
        @$dom->loadHTML($content);

        // Create XPath object
        $xpath = new DOMXPath($dom);

        try {
            // Extract title using XPath
            $titleNodes = $xpath->query('//title | //h1[@class="page-title"] | //h1[@class="document-title"] | //h1');
            if ($titleNodes->length > 0) {
                $extractedTitle = trim($titleNodes->item(0)->textContent);
                if (!empty($extractedTitle)) {
                    $metadata['title'] = $extractedTitle;
                }
            }

            // Extract meta description for summary using XPath
            $descriptionNodes = $xpath->query('//meta[@name="description"]/@content | //meta[@property="og:description"]/@content');
            if ($descriptionNodes->length > 0) {
                $description = trim($descriptionNodes->item(0)->nodeValue);
                if (!empty($description)) {
                    $metadata['summary'] = $description;
                }
            }

            // Extract keywords using XPath
            $keywordNodes = $xpath->query('//meta[@name="keywords"]/@content');
            if ($keywordNodes->length > 0) {
                $keywords = trim($keywordNodes->item(0)->nodeValue);
                if (!empty($keywords)) {
                    $keywordArray = array_map('trim', explode(',', $keywords));
                    $metadata['extracted_keywords'] = array_filter($keywordArray);
                }
            }

            // Extract language using XPath
            $langNodes = $xpath->query('//html/@lang | //meta[@http-equiv="content-language"]/@content');
            if ($langNodes->length > 0) {
                $lang = trim($langNodes->item(0)->nodeValue);
                if (!empty($lang)) {
                    $metadata['language'] = substr($lang, 0, 2); // Get first 2 characters
                }
            }

            // Extract publication date using comprehensive XPath selectors
            $dateXPaths = [
                '//meta[@name="date"]/@content',
                '//meta[@property="article:published_time"]/@content',
                '//meta[@name="publication-date"]/@content',
                '//meta[@name="DC.date"]/@content',
                '//meta[@name="DC.Date"]/@content',
                '//time[@datetime]/@datetime',
                '//span[@class="date"]',
                '//div[@class="publication-date"]',
                '//p[contains(@class, "date")]'
            ];

            foreach ($dateXPaths as $dateXPath) {
                $dateNodes = $xpath->query($dateXPath);
                if ($dateNodes->length > 0) {
                    $dateValue = trim($dateNodes->item(0)->nodeValue);
                    if (!empty($dateValue)) {
                        try {
                            $metadata['publication_date'] = Carbon::parse($dateValue)->format('Y-m-d');
                            break;
                        } catch (\Exception $e) {
                            // Continue to next selector if date parsing fails
                        }
                    }
                }
            }

            // Extract document type and category information
            $metadata['document_type'] = $this->determineDocumentType($url, $content, $xpath);

            // Extract case references using XPath
            $caseRefNodes = $xpath->query('//span[contains(@class, "case-ref")] | //div[contains(@class, "reference")] | //p[contains(text(), "Kamerstuk") or contains(text(), "Zaak")]');
            if ($caseRefNodes->length > 0) {
                $caseReferences = [];
                foreach ($caseRefNodes as $node) {
                    $ref = trim($node->textContent);
                    if (!empty($ref)) {
                        $caseReferences[] = $ref;
                    }
                }
                if (!empty($caseReferences)) {
                    $metadata['case_references'] = $caseReferences;
                }
            }

            // Extract structured document details (as shown in the UI)
            $this->extractStructuredDocumentDetails($xpath, $metadata);

            // Extract entities (organizations, people, locations) using XPath
            $entityNodes = $xpath->query('//span[@class="entity"] | //a[contains(@href, "organisatie")] | //strong[contains(@class, "organization")]');
            if ($entityNodes->length > 0) {
                $entities = [];
                foreach ($entityNodes as $node) {
                    $entity = trim($node->textContent);
                    if (!empty($entity) && strlen($entity) > 2) {
                        $entities[] = $entity;
                    }
                }
                if (!empty($entities)) {
                    $metadata['entities'] = array_unique($entities);
                }
            }

            // Extract ROO identifier if present
            $rooNodes = $xpath->query('//meta[@name="roo-identifier"]/@content | //span[@class="roo-id"] | //div[contains(@class, "identifier")]');
            if ($rooNodes->length > 0) {
                $rooId = trim($rooNodes->item(0)->nodeValue);
                if (!empty($rooId)) {
                    $metadata['roo_identifier'] = $rooId;
                }
            }

            // Store comprehensive metadata (same structure as CrawlOrganisationsCommand)
            $metadata['metadata'] = [
                'url' => $url,
                'content_length' => strlen($content),
                'extracted_at' => now()->toISOString(),
                'extraction_method' => 'DOMDocument_XPath',
                'dom_elements_found' => [
                    'titles' => $titleNodes->length,
                    'meta_tags' => $xpath->query('//meta')->length,
                    'headings' => $xpath->query('//h1 | //h2 | //h3')->length,
                    'paragraphs' => $xpath->query('//p')->length,
                    'links' => $xpath->query('//a[@href]')->length,
                ],
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to extract metadata from document using DOMDocument/XPath', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to basic extraction if DOM parsing fails
            $metadata['error_message'] = 'DOM parsing failed, using fallback extraction';
            $metadata['metadata'] = [
                'url' => $url,
                'content_length' => strlen($content),
                'extracted_at' => now()->toISOString(),
                'extraction_method' => 'fallback',
                'error' => $e->getMessage(),
            ];
        }

        return $metadata;
    }

    /**
     * Determine document type based on URL, content, and XPath analysis (same approach as CrawlOrganisationsCommand).
     */
    protected function determineDocumentType(string $url, string $content, DOMXPath $xpath = null): string
    {
        // Check URL for file extensions first
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        if ($extension) {
            return strtoupper($extension);
        }

        // Use XPath to analyze document structure if available
        if ($xpath) {
            try {
                // Check for specific document type indicators using XPath
                $docTypeNodes = $xpath->query('//meta[@name="document-type"]/@content | //span[@class="document-type"] | //div[@class="doc-type"]');
                if ($docTypeNodes->length > 0) {
                    $docType = trim($docTypeNodes->item(0)->nodeValue);
                    if (!empty($docType)) {
                        return strtoupper($docType);
                    }
                }

                // Check for government document patterns
                $govDocNodes = $xpath->query('//h1[contains(text(), "Kamervraag")] | //h1[contains(text(), "Wetsvoorstel")] | //h1[contains(text(), "Besluit")] | //span[contains(@class, "document-category")]');
                if ($govDocNodes->length > 0) {
                    $docText = trim($govDocNodes->item(0)->textContent);
                    if (Str::contains($docText, ['Kamervraag', 'Parliamentary'])) {
                        return 'KAMERVRAAG';
                    }
                    if (Str::contains($docText, ['Wetsvoorstel', 'Bill', 'Wet van'])) {
                        return 'WETSVOORSTEL';
                    }
                    if (Str::contains($docText, ['Besluit', 'Decision'])) {
                        return 'BESLUIT';
                    }
                }

                // Check for PDF indicators
                $pdfNodes = $xpath->query('//a[contains(@href, ".pdf")] | //embed[@type="application/pdf"] | //object[@type="application/pdf"]');
                if ($pdfNodes->length > 0) {
                    return 'PDF';
                }

                // Check for structured data indicators
                $structuredNodes = $xpath->query('//script[@type="application/ld+json"] | //div[@itemscope]');
                if ($structuredNodes->length > 0) {
                    return 'STRUCTURED_HTML';
                }

            } catch (\Exception $e) {
                Log::warning('Error analyzing document type with XPath', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback to content type patterns
        if (strpos($content, '<?xml') !== false) {
            return 'XML';
        }
        
        if (strpos($content, '<!DOCTYPE html') !== false || strpos($content, '<html') !== false) {
            return 'HTML';
        }

        // Check for JSON content
        if (Str::startsWith(trim($content), ['{', '['])) {
            return 'JSON';
        }

        return 'UNKNOWN';
    }

    /**
     * Check if the response contains a downloadable file.
     */
    protected function isDownloadableFile($response): bool
    {
        $contentType = $response->header('Content-Type', '');
        
        $downloadableTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ];

        foreach ($downloadableTypes as $type) {
            if (strpos($contentType, $type) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store document file to storage.
     */
    protected function storeDocumentFile(string $content, Document $document, $response): ?string
    {
        try {
            $contentType = $response->header('Content-Type', '');
            $extension = $this->getFileExtensionFromContentType($contentType);
            
            $filename = 'documents/' . date('Y/m/d') . '/' . $document->id . '_' . time() . '.' . $extension;
            
            Storage::disk('local')->put($filename, $content);
            
            // Update file extension without syncing to search
            Document::withoutSyncingToSearch(function () use ($document, $extension) {
                $document->update(['file_extension' => $extension]);
            });
            
            return $filename;
        } catch (\Exception $e) {
            Log::error('Failed to store document file', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get file extension from content type.
     */
    protected function getFileExtensionFromContentType(string $contentType): string
    {
        $extensions = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip' => 'zip',
            'text/html' => 'html',
            'text/plain' => 'txt',
        ];

        foreach ($extensions as $type => $ext) {
            if (strpos($contentType, $type) !== false) {
                return $ext;
            }
        }

        return 'bin';
    }

    /**
     * Handle document processing errors.
     */
    protected function handleDocumentError(Document $document, \Exception $e): void
    {
        $errorMessage = $e->getMessage();
        
        Document::withoutSyncingToSearch(function () use ($document, $errorMessage) {
            $document->update([
                'error_message' => $errorMessage,
                'last_checked_timestamp' => now(),
                'is_processed' => false,
            ]);
        });

        Log::error('Document processing failed', [
            'document_id' => $document->id,
            'url' => $document->source_url,
            'error' => $errorMessage
        ]);

        $this->error("Error processing document {$document->id}: {$errorMessage}");
    }

    /**
     * Extract structured document details as shown in the UI (Verantwoordelijke, Thema, etc.)
     */
    protected function extractStructuredDocumentDetails(DOMXPath $xpath, array &$metadata): void
    {
        try {
            // Extract structured data from the document details table
            $detailsTable = $xpath->query('//table | //dl | //div[contains(@class, "document-details")] | //div[contains(@class, "metadata")]');
            
            if ($detailsTable->length > 0) {
                // Look for key-value pairs in various formats
                $this->extractKeyValuePairs($xpath, $metadata);
            }

            // Extract specific fields commonly found in government documents
            $this->extractGovernmentDocumentFields($xpath, $metadata);

            // Extract publication information
            $this->extractPublicationInfo($xpath, $metadata);

        } catch (\Exception $e) {
            Log::warning('Failed to extract structured document details', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Extract key-value pairs from document structure
     */
    protected function extractKeyValuePairs(DOMXPath $xpath, array &$metadata): void
    {
        // Common patterns for key-value pairs in government documents
        $patterns = [
            // Table rows with th/td structure
            '//tr[th and td]',
            // Definition lists
            '//dt[following-sibling::dd]',
            // Divs with label/value structure
            '//div[contains(@class, "field") or contains(@class, "row")]//label[following-sibling::*]',
        ];

        $extractedFields = [];

        foreach ($patterns as $pattern) {
            $nodes = $xpath->query($pattern);
            
            foreach ($nodes as $node) {
                $key = '';
                $value = '';

                if ($node->nodeName === 'tr') {
                    $thNode = $xpath->query('.//th', $node)->item(0);
                    $tdNode = $xpath->query('.//td', $node)->item(0);
                    
                    if ($thNode && $tdNode) {
                        $key = trim($thNode->textContent);
                        $value = trim($tdNode->textContent);
                    }
                } elseif ($node->nodeName === 'dt') {
                    $key = trim($node->textContent);
                    $ddNode = $xpath->query('following-sibling::dd[1]', $node)->item(0);
                    if ($ddNode) {
                        $value = trim($ddNode->textContent);
                    }
                } elseif ($node->nodeName === 'label') {
                    $key = trim($node->textContent);
                    $valueNode = $xpath->query('following-sibling::*[1]', $node)->item(0);
                    if ($valueNode) {
                        $value = trim($valueNode->textContent);
                    }
                }

                if (!empty($key) && !empty($value)) {
                    $normalizedKey = $this->normalizeFieldKey($key);
                    if ($normalizedKey) {
                        $extractedFields[$normalizedKey] = $value;
                    }
                }
            }
        }

        // Map extracted fields to metadata
        $this->mapExtractedFields($extractedFields, $metadata);
    }

    /**
     * Extract government document specific fields
     */
    protected function extractGovernmentDocumentFields(DOMXPath $xpath, array &$metadata): void
    {
        // Look for specific government document patterns
        $governmentFields = [
            'verantwoordelijke' => '//text()[contains(translate(., "VERANTWOORDELIJKE", "verantwoordelijke"), "verantwoordelijke")]/following::text()[1] | //th[contains(translate(., "VERANTWOORDELIJKE", "verantwoordelijke"), "verantwoordelijke")]/following-sibling::td',
            'thema' => '//text()[contains(translate(., "THEMA", "thema"), "thema")]/following::text()[1] | //th[contains(translate(., "THEMA", "thema"), "thema")]/following-sibling::td',
            'documentsoort' => '//text()[contains(translate(., "DOCUMENTSOORT", "documentsoort"), "documentsoort")]/following::text()[1] | //th[contains(translate(., "DOCUMENTSOORT", "documentsoort"), "documentsoort")]/following-sibling::td',
            'onderwerp' => '//text()[contains(translate(., "ONDERWERP", "onderwerp"), "onderwerp")]/following::text()[1] | //th[contains(translate(., "ONDERWERP", "onderwerp"), "onderwerp")]/following-sibling::td',
        ];

        foreach ($governmentFields as $field => $xpathQuery) {
            $nodes = $xpath->query($xpathQuery);
            if ($nodes->length > 0) {
                $value = trim($nodes->item(0)->textContent);
                if (!empty($value)) {
                    $metadata['government_fields'][$field] = $value;
                }
            }
        }
    }

    /**
     * Extract publication information and dates
     */
    protected function extractPublicationInfo(DOMXPath $xpath, array &$metadata): void
    {
        // Look for publication dates and creation dates
        $dateFields = [
            'geldig_van' => '//text()[contains(translate(., "GELDIG VAN", "geldig van"), "geldig van")]/following::text()[normalize-space()][1]',
            'document_creatiedatum' => '//text()[contains(translate(., "CREATIEDATUM", "creatiedatum"), "creatiedatum")]/following::text()[normalize-space()][1]',
            'publicatiedatum' => '//text()[contains(translate(., "PUBLICATIE", "publicatie"), "publicatie")]/following::text()[normalize-space()][1]',
        ];

        foreach ($dateFields as $field => $xpathQuery) {
            $nodes = $xpath->query($xpathQuery);
            if ($nodes->length > 0) {
                $dateValue = trim($nodes->item(0)->textContent);
                if (!empty($dateValue)) {
                    try {
                        $parsedDate = Carbon::parse($dateValue)->format('Y-m-d');
                        $metadata['dates'][$field] = $parsedDate;
                    } catch (\Exception $e) {
                        $metadata['dates'][$field] = $dateValue; // Store as-is if parsing fails
                    }
                }
            }
        }
    }

    /**
     * Normalize field keys to standard format
     */
    protected function normalizeFieldKey(string $key): ?string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9\s]/', '', $key);
        $key = preg_replace('/\s+/', '_', $key);

        // Map common field names
        $fieldMap = [
            'verantwoordelijke' => 'responsible_authority',
            'thema' => 'theme',
            'documentsoort' => 'document_type_detail',
            'geldig_van' => 'valid_from',
            'document_creatiedatum' => 'document_creation_date',
            'onderwerp' => 'subject',
            'publicatie' => 'publication',
        ];

        return $fieldMap[$key] ?? ($key ?: null);
    }

    /**
     * Apply mapped database fields from extracted structured data to update data
     */
    protected function applyMappedFieldsToUpdateData(array $metadata, array &$updateData): void
    {
        // Apply database field mappings if they exist
        if (isset($metadata['database_fields']) && is_array($metadata['database_fields'])) {
            foreach ($metadata['database_fields'] as $column => $value) {
                if (!empty($value)) {
                    $updateData[$column] = $value;
                    $this->line("  Mapped field: {$column} = {$value}");
                }
            }
        }

        // Apply additional structured data mappings
        if (isset($metadata['government_entity_name']) && !empty($metadata['government_entity_name'])) {
            $updateData['government_entity_name'] = $metadata['government_entity_name'];
        }

        if (isset($metadata['subject']) && !empty($metadata['subject'])) {
            // Use subject as summary if summary is not already set
            if (empty($updateData['summary'])) {
                $updateData['summary'] = $metadata['subject'];
            }
        }

        if (isset($metadata['document_type_detail']) && !empty($metadata['document_type_detail'])) {
            // Update document_type if not already set or if the extracted value is more specific
            if (empty($updateData['document_type']) || strlen($metadata['document_type_detail']) > strlen($updateData['document_type'] ?? '')) {
                $updateData['document_type'] = $metadata['document_type_detail'];
            }
        }

        // Store theme and other additional fields in metadata for future use
        if (isset($metadata['theme']) && !empty($metadata['theme'])) {
            $updateData['metadata'] = array_merge($updateData['metadata'] ?? [], [
                'theme' => $metadata['theme']
            ]);
        }

        if (isset($metadata['document_creation_date']) && !empty($metadata['document_creation_date'])) {
            $updateData['metadata'] = array_merge($updateData['metadata'] ?? [], [
                'document_creation_date' => $metadata['document_creation_date']
            ]);
        }
    }

    /**
     * Map extracted fields to database columns and metadata structure
     */
    protected function mapExtractedFields(array $extractedFields, array &$metadata): void
    {
        // Initialize database field mappings
        $metadata['database_fields'] = [];
        
        foreach ($extractedFields as $key => $value) {
            switch ($key) {
                case 'responsible_authority':
                case 'verantwoordelijke':
                    $metadata['database_fields']['government_entity_name'] = $value;
                    $metadata['government_entity_name'] = $value;
                    break;
                case 'theme':
                case 'thema':
                    $metadata['theme'] = $value;
                    $metadata['additional_fields']['theme'] = $value;
                    break;
                case 'document_type_detail':
                case 'documentsoort':
                    $metadata['database_fields']['document_type'] = $value;
                    $metadata['document_type_detail'] = $value;
                    break;
                case 'subject':
                case 'onderwerp':
                    $metadata['database_fields']['summary'] = $value;
                    $metadata['subject'] = $value;
                    break;
                case 'valid_from':
                case 'geldig_van':
                    try {
                        $parsedDate = Carbon::parse($value)->format('Y-m-d');
                        $metadata['database_fields']['publication_date'] = $parsedDate;
                        $metadata['valid_from'] = $parsedDate;
                    } catch (\Exception $e) {
                        $metadata['valid_from'] = $value;
                        Log::warning('Failed to parse date', ['field' => $key, 'value' => $value]);
                    }
                    break;
                case 'document_creatiedatum':
                case 'document_creation_date':
                    try {
                        $parsedDate = Carbon::parse($value)->format('Y-m-d');
                        $metadata['document_creation_date'] = $parsedDate;
                        $metadata['additional_fields']['document_creation_date'] = $parsedDate;
                    } catch (\Exception $e) {
                        $metadata['document_creation_date'] = $value;
                        Log::warning('Failed to parse creation date', ['field' => $key, 'value' => $value]);
                    }
                    break;
                default:
                    $metadata['additional_fields'][$key] = $value;
                    break;
            }
        }
    }

    /**
     * Download linked documents (PDFs) and store them
     */
    protected function downloadLinkedDocuments(array $metadata, Document $document, array &$updateData): void
    {
        try {
            // Look for PDF links in the content
            if (isset($metadata['metadata']['dom_elements_found'])) {
                $this->findAndDownloadPDFs($document, $updateData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to download linked documents', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Find and download PDF documents from the page
     */
    protected function findAndDownloadPDFs(Document $document, array &$updateData): void
    {
        try {
            // Re-fetch the page to look for PDF links
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                ])
                ->get($document->source_url);

            if ($response->successful()) {
                $dom = new DOMDocument;
                @$dom->loadHTML($response->body());
                $xpath = new DOMXPath($dom);

                // Look for PDF links
                $pdfLinks = $xpath->query('//a[contains(@href, ".pdf")] | //a[contains(translate(@href, "PDF", "pdf"), "pdf")]');
                
                foreach ($pdfLinks as $link) {
                    $pdfUrl = $link->getAttribute('href');
                    
                    // Make URL absolute if it's relative
                    if (!Str::startsWith($pdfUrl, ['http://', 'https://'])) {
                        $baseUrl = parse_url($document->source_url, PHP_URL_SCHEME) . '://' . parse_url($document->source_url, PHP_URL_HOST);
                        $pdfUrl = $baseUrl . (Str::startsWith($pdfUrl, '/') ? '' : '/') . $pdfUrl;
                    }

                    // Download the PDF
                    $pdfPath = $this->downloadPDF($pdfUrl, $document);
                    if ($pdfPath) {
                        $updateData['archived_file_path'] = $pdfPath;
                        $updateData['file_extension'] = 'pdf';
                        
                        // Get file size
                        $fullPath = Storage::disk('local')->path($pdfPath);
                        if (file_exists($fullPath)) {
                            $updateData['file_size'] = filesize($fullPath);
                            $updateData['checksum'] = hash_file('md5', $fullPath);
                        }
                        
                        $this->line("Downloaded PDF: {$pdfPath}");
                        break; // Download only the first PDF found
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to find and download PDFs', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Download a PDF file from URL
     */
    protected function downloadPDF(string $pdfUrl, Document $document): ?string
    {
        try {
            $this->line("Downloading PDF from: {$pdfUrl}");
            
            $response = Http::timeout(120)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                ])
                ->get($pdfUrl);

            if ($response->successful()) {
                $content = $response->body();
                
                // Verify it's actually a PDF
                if (strpos($content, '%PDF') === 0) {
                    $filename = 'documents/' . date('Y/m/d') . '/' . $document->id . '_' . time() . '.pdf';
                    
                    Storage::disk('local')->put($filename, $content);
                    
                    return $filename;
                } else {
                    Log::warning('Downloaded file is not a valid PDF', [
                        'document_id' => $document->id,
                        'url' => $pdfUrl
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to download PDF', [
                'document_id' => $document->id,
                'url' => $pdfUrl,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class . ' --limit=50')
            ->everyTwoHours()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(base_path('logs/documents-process.log'));
    }
}
