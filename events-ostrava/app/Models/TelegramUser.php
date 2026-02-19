<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
