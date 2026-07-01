<?php

namespace LinguaLayer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source_hash
 * @property string $source_text
 * @property string $source_lang
 * @property string $target_lang
 * @property string $translated_text
 * @property string|null $model_used
 * @property int|null $score
 * @property string|null $page_url
 * @property string|null $element_path
 * @property int $times_used
 * @property Carbon|null $last_seen_at
 * @property bool $is_obsolete
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Translation extends Model
{
    protected $table = 'lingua_translations';

    protected $fillable = [
        'source_hash',
        'source_text',
        'source_lang',
        'target_lang',
        'translated_text',
        'model_used',
        'score',
        'page_url',
        'element_path',
        'times_used',
        'last_seen_at',
        'is_obsolete',
    ];

    protected $casts = [
        'is_obsolete' => 'bool',
        'last_seen_at' => 'datetime',
        'score' => 'int',
        'times_used' => 'int',
    ];

    /** Active = not obsolete. Default lookup path. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_obsolete', false);
    }

    public function scopeObsolete(Builder $q): Builder
    {
        return $q->where('is_obsolete', true);
    }

    public function scopeForLang(Builder $q, string $targetLang): Builder
    {
        return $q->where('target_lang', $targetLang);
    }

    public function scopeRecentlyUsed(Builder $q): Builder
    {
        return $q->orderByDesc('last_seen_at');
    }

    /**
     * Touch usage counter and timestamp. Single UPDATE, no model reload.
     */
    public function markAsSeen(): void
    {
        $this->forceFill([
            'last_seen_at' => now(),
            'times_used' => $this->times_used + 1,
        ])->save();
    }

    public function markObsolete(): void
    {
        if ($this->is_obsolete) {
            return;
        }
        $this->forceFill(['is_obsolete' => true])->save();
    }

    /**
     * Stale = not seen in N days. Independent of obsolete flag — a row can be
     * stale-but-valid (eligible for eviction) or fresh-and-obsolete (just
     * superseded by a newer translation on the same page).
     */
    public function isStale(int $days = 30): bool
    {
        if ($this->last_seen_at === null) {
            return $this->created_at !== null
                && $this->created_at->lt(now()->subDays($days));
        }

        return $this->last_seen_at->lt(now()->subDays($days));
    }

    /**
     * Canonical hash used as the cache key. Includes source_lang so the same
     * literal string in two source languages does not collide.
     */
    public static function makeHash(string $text, string $sourceLang): string
    {
        return hash('sha256', $text.'|'.$sourceLang);
    }
}
