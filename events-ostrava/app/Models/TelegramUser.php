<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property string|null $timezone
 * @property string|null $language
 * @property bool|null $notify_enabled
 * @property Carbon|null $notify_last_sent_at
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
    ];

    protected $casts = [
        'notify_enabled' => 'boolean',
        'notify_last_sent_at' => 'datetime',
    ];
}
