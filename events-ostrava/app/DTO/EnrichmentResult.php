<?php
declare(strict_types=1);

namespace App\DTO;

final class EnrichmentResult
{
    public function __construct(
        public int $logId,
        public array $fields,
        public string $mode
    ) {
    }
}
