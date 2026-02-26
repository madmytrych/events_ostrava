<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property string|null $timezone
 * @property string|null $language
 * @property int|null $age_min
 * @property int|null $age_max
 * @property bool|null $notify_enabled
 * @property Carbon|null $notify_last_sent_at
 * @property bool|null $notify_new_events
 * @property Carbon|null $notify_new_events_last_sent_at
 * @property string|null $submission_state
 * @property string|null $submission_url
 * @property string|null $submission_name
 * @property string|null $submission_description
 * @property string|null $submission_contact
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TelegramUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'timezone',
        'language',
        'age_min',
        'age_max',
    ];

    protected $casts = [
        'notify_enabled' => 'boolean',
        'notify_last_sent_at' => 'datetime',
        'notify_new_events' => 'boolean',
        'notify_new_events_last_sent_at' => 'datetime',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class, 'chat_id', 'chat_id');
    }
}
