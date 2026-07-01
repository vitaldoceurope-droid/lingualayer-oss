<?php

namespace LinguaLayer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $target_lang
 * @property int $pages_total
 * @property int $pages_translated
 * @property int $pages_pending
 * @property int $pages_failed
 * @property int $fragments_total
 * @property int $fragments_translated
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $estimated_seconds_remaining
 * @property string|null $last_page_completed
 * @property string $status
 */
class AgentProgress extends Model
{
    protected $table = 'lingua_agent_progress';

    protected $fillable = [
        'target_lang',
        'pages_total',
        'pages_translated',
        'pages_pending',
        'pages_failed',
        'fragments_total',
        'fragments_translated',
        'started_at',
        'completed_at',
        'estimated_seconds_remaining',
        'last_page_completed',
        'status',
    ];

    protected $casts = [
        'pages_total' => 'int',
        'pages_translated' => 'int',
        'pages_pending' => 'int',
        'pages_failed' => 'int',
        'fragments_total' => 'int',
        'fragments_translated' => 'int',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_seconds_remaining' => 'int',
    ];

    public function percentage(): float
    {
        if ($this->pages_total <= 0) {
            return 0.0;
        }

        return round(($this->pages_translated / $this->pages_total) * 100, 2);
    }
}
