<?php
declare(strict_types=1);

namespace App\Services\Enrichment;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Services\Enrichment\Providers\AiEnrichmentProvider;
use App\Services\Enrichment\Providers\RulesEnrichmentProvider;

final class EnrichmentService
{
    public function __construct(
        private AiEnrichmentProvider $aiProvider,
        private RulesEnrichmentProvider $rulesProvider
    ) {
    }

    public function enrich(Event $event): EnrichmentResult
    {
        $mode = (string) config('enrichment.mode', 'hybrid');
        $aiEnabled = (bool) config('enrichment.ai_enabled', true);

        $result = null;
        $usedMode = 'rules';

        if ($mode === 'ai' || ($mode === 'hybrid' && $aiEnabled)) {
            try {
                $result = $this->aiProvider->enrich($event, 'ai');
                $usedMode = 'ai';
            } catch (\Throwable $e) {
                if ($mode !== 'hybrid') {
                    throw $e;
                }
            }
        }

        if (!$result) {
            $reason = $usedMode === 'ai' ? 'fallback' : 'rules';
            $result = $this->rulesProvider->enrich($event, $reason);
        }

        return $result;
    }
}
