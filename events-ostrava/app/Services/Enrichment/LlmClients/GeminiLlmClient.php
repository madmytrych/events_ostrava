<?php

declare(strict_types=1);

namespace App\Services\Enrichment\LlmClients;

use App\Services\Enrichment\Contracts\LlmClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class GeminiLlmClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.0-flash-lite',
        private int $timeout = 45,
    ) {}

    /**
     * @throws ConnectionException
     */
    public function complete(string $prompt): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $this->model,
            $this->apiKey,
        );

        $response = Http::timeout($this->timeout)
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'temperature' => 0.2,
                ],
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException(
                'Gemini API request failed: ' . $response->body(),
            );
        }

        $content = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (!is_string($content) || $content === '') {
            throw new \RuntimeException(
                'Gemini returned no content: ' . $response->body(),
            );
        }

        return $content;
    }
}
