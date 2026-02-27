<?php

declare(strict_types=1);

namespace App\Services\Enrichment\LlmClients;

use App\Services\Enrichment\Contracts\LlmClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class OpenAiLlmClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o-mini',
        private string $url = 'https://api.openai.com/v1/chat/completions',
        private int $timeout = 45,
    ) {}

    /**
     * @throws ConnectionException
     */
    public function complete(string $prompt): string
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url, [
                'model' => $this->model,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Return ONLY valid JSON. No markdown. No extra keys.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (!$response->ok()) {
            throw new \RuntimeException(
                'OpenAI API request failed: ' . $response->body(),
            );
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (!is_string($content) || $content === '') {
            throw new \RuntimeException(
                'OpenAI returned no content: ' . $response->body(),
            );
        }

        return $content;
    }
}
