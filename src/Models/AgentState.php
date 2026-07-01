<?php

namespace LinguaLayer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $last_full_scan_at
 * @property Carbon|null $last_change_check_at
 * @property Carbon|null $last_quality_check_at
 * @property int $pages_known
 * @property string|null $routes_signature
 * @property bool $enabled
 */
class AgentState extends Model
{
    protected $table = 'lingua_agent_state';

    protected $fillable = [
        'last_full_scan_at',
        'last_change_check_at',
        'last_quality_check_at',
        'pages_known',
        'routes_signature',
        'enabled',
    ];

    protected $casts = [
        'last_full_scan_at' => 'datetime',
        'last_change_check_at' => 'datetime',
        'last_quality_check_at' => 'datetime',
        'pages_known' => 'int',
        'enabled' => 'bool',
    ];

    /**
     * Singleton: the agent state is conceptually a single row. We firstOrCreate
     * to keep the API simple — callers always get a usable instance.
     */
    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'pages_known' => 0,
            'enabled' => true,
        ]);
    }
}
