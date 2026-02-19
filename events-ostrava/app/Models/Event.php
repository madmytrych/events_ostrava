<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source
 * @property string $source_url
 * @property string $source_event_id
 * @property string $title
 * @property Carbon $start_at
 * @property Carbon|null $end_at
 * @property string|null $venue
 * @property string|null $location_name
 * @property string|null $address
 * @property string|null $price_text
 * @property string|null $description
 * @property string|null $description_raw
 * @property string|null $summary
 * @property string|null $short_summary
 * @property int|null $age_min
 * @property int|null $age_max
 * @property array|null $tags
 * @property bool|null $kid_friendly
 * @property string|null $indoor_outdoor
 * @property string|null $category
 * @property string|null $language
 * @property bool|null $needs_review
 * @property string $fingerprint
 * @property string|null $status
 * @property Carbon|null $enriched_at
 * @property int|null $enrichment_attempts
 * @property int|null $enrichment_log_id
 * @property int|null $duplicate_of_event_id
 * @property bool|null $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'source','source_url','source_event_id',
        'title','start_at','end_at','venue','location_name','address','price_text',
        'description','description_raw','summary','short_summary',
        'title_i18n','summary_i18n','short_summary_i18n',
        'age_min','age_max','tags','kid_friendly',
        'indoor_outdoor','category','language',
        'fingerprint',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'enriched_at' => 'datetime',
        'tags' => 'array',
        'title_i18n' => 'array',
        'summary_i18n' => 'array',
        'short_summary_i18n' => 'array',
        'kid_friendly' => 'boolean',
        'needs_review' => 'boolean',
        'is_active' => 'boolean',
    ];
}
