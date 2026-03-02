<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\DTO\EnrichmentResult;
use App\Models\Event;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Enrichment\Providers\RulesEnrichmentProvider;

final readonly class EnrichmentService
{
    public function __construct(
        private EnrichmentProviderInterface $aiProvider,
        private RulesEnrichmentProvider $rulesProvider
    ) {}

    /**
     * @throws \Throwable
     */
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

                if ($result->fields['age_min'] === null && $result->fields['age_max'] === null) {
                    [$ageMin, $ageMax] = $this->rulesProvider->extractAgeFromEvent($event);
                    if ($ageMin !== null || $ageMax !== null) {
                        $result->fields['age_min'] = $ageMin;
                        $result->fields['age_max'] = $ageMax;
                    }
                }
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
