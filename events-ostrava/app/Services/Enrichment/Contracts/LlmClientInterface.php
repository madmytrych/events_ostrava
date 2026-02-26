<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Contracts;

interface LlmClientInterface
{
    /**
     * Send a prompt and return the raw JSON string from the model.
     *
     * @throws \RuntimeException on API failure or non-JSON response
     */
    public function complete(string $prompt): string;
}
