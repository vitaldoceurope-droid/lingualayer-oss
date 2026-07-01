<?php

namespace LinguaLayer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LinguaLayer\Services\ProgressTracker;

/**
 * Fires when a single language completes (or the whole site reaches 100%).
 * Channel is configured via lingua.agent.notification_channel:
 *   - 'log'     (default)  — writes to the host logger
 *   - 'webhook'             — POSTs JSON to lingua.agent.webhook_url
 *   - 'slack' / 'email'     — placeholder, currently degrade to log
 *
 * Always also pushes to the dashboard notification ring so the UI updates
 * regardless of channel.
 */
class SendCompletionNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly ?string $language = null,
        private readonly bool $allCompleted = false,
        private readonly array $payload = [],
    ) {}

    public function handle(ProgressTracker $tracker): void
    {
        $channel = (string) config('lingua.agent.notification_channel', 'log');

        if ($this->allCompleted) {
            $message = '🎉 ALL LANGUAGES COMPLETED at '.now()->toDateTimeString();
            $tracker->pushNotification([
                'type' => 'all_completed',
                'timestamp' => now()->toIso8601String(),
                'payload' => $this->payload,
            ]);
        } else {
            $message = sprintf('🎉 [%s] completed at %s', $this->language ?? 'lang', now()->toDateTimeString());
            $tracker->pushNotification([
                'type' => 'completed',
                'language' => $this->language,
                'timestamp' => now()->toIso8601String(),
                'payload' => $this->payload,
            ]);
        }

        Log::channel('single')->info('[LinguaLayer][agent] '.$message);

        if ($channel === 'webhook') {
            $url = (string) config('lingua.agent.webhook_url', '');
            if ($url !== '') {
                try {
                    Http::timeout(10)->asJson()->post($url, [
                        'event' => $this->allCompleted ? 'all_completed' : 'language_completed',
                        'language' => $this->language,
                        'all_completed' => $this->allCompleted,
                        'message' => $message,
                        'timestamp' => now()->toIso8601String(),
                        'payload' => $this->payload,
                    ]);
                } catch (\Throwable $e) {
                    Log::channel('single')->warning('[LinguaLayer][agent] webhook failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
