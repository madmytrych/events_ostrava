<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Contracts;

use App\DTO\EnrichmentResult;
use App\Models\Event;

interface EnrichmentProviderInterface
{
    public function enrich(Event $event, string $reason = 'rules'): EnrichmentResult;
}
