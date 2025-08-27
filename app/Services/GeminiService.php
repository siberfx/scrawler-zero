<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiService
{
    /**
     * The base URL for the Gemini API.
     *
     * @var string
     */
    protected $apiUrl;

    /**
     * The API key for authentication.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * The model to be used for generating content.
     *
     * @var string
     */
    protected $model;

    /**
     * Create a new Gemini service instance.
     *
     * @param  string|null  $apiKey
     * @param  string|null  $model
     */
    public function __construct($apiKey = null, $model = null)
    {
        $this->apiUrl = config('gemini.api_url');
        $this->apiKey = $apiKey ?? config('gemini.api_key');
        $this->model = $model ?? config('gemini.default_model');
    }

    /**
     * Generate content using the Gemini API.
     */
    public function generateContent(string $prompt, array $options = []): ?array
    {
        try {
            $url = "{$this->apiUrl}?key={$this->apiKey}";

            $payload = array_merge([
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.9,
                    'topK' => 1,
                    'topP' => 1,
                    'maxOutputTokens' => 2048,
                    'stopSequences' => [],
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                    ],
                ],
            ], $options);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Gemini API request failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return null;

        } catch (RequestException $e) {
            Log::error('Gemini API request exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Generate a simple text response from the prompt.
     */
    public function generateText(string $prompt): ?string
    {
        $response = $this->generateContent($prompt);

        if ($response && isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }

        return null;
    }

    /**
     * Generate content with streaming support.
     *
     * @param  callable  $callback
     * @return bool
     */
    /**
     * Process a file with Gemini API.
     *
     * @param  string  $filePath  Path to the file in storage
     * @param  string  $prompt  The prompt to use for processing
     * @param  string  $mimeType  The MIME type of the file
     */
    public function processFile(string $filePath, string $prompt, string $mimeType): ?array
    {
        try {
            if (! Storage::disk('public')->exists($filePath)) {
                throw new \Exception("File not found: {$filePath}");
            }

            $fileContent = base64_encode(Storage::disk('public')->get($filePath));

            $url = "{$this->apiUrl}?key={$this->apiKey}";

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $fileContent,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'topK' => 32,
                    'topP' => 1,
                    'maxOutputTokens' => 4096,
                ],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Gemini file processing failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Gemini file processing error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Generate content with streaming support.
     */
    public function streamGenerateContent(string $prompt, callable $callback): bool
    {
        try {
            $url = "{$this->apiUrl}?key={$this->apiKey}";

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.9,
                    'topK' => 1,
                    'topP' => 1,
                    'maxOutputTokens' => 2048,
                ],
                'stream' => true,
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody(
                json_encode($payload),
                'application/json'
            )->withOptions([
                'stream' => true,
            ])->post($url);

            if ($response->successful()) {
                $response->collect('candidates')->each(function ($candidate) use ($callback) {
                    if (isset($candidate['content']['parts'][0]['text'])) {
                        $callback($candidate['content']['parts'][0]['text']);
                    }
                });

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Gemini streaming error: '.$e->getMessage());

            return false;
        }
    }
}
