<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $mode
 * @property string|null $prompt
 * @property string|null $response
 * @property string $status
 * @property int|null $tokens_prompt
 * @property int|null $tokens_completion
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventEnrichmentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'mode',
        'prompt',
        'response',
        'status',
        'tokens_prompt',
        'tokens_completion',
        'duration_ms',
        'error',
    ];
}
