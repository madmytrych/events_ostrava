<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property string|null $url
 * @property string|null $name
 * @property string|null $description
 * @property string|null $contact
 * @property string|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'url',
        'name',
        'description',
        'contact',
        'status',
    ];
}
