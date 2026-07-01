<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use LinguaLayer\Http\Middleware\TranslateResponse;
use LinguaLayer\Jobs\WarmAllPagesJob;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

function fireTick(int $status = 200, string $method = 'GET'): void
{
    $mw = app(TranslateResponse::class);
    $req = Request::create('/', $method);
    $resp = new SymfonyResponse('<html><body>ok</body></html>', $status);
    $mw->terminate($req, $resp);
}

beforeEach(function () {
    Cache::driver(config('lingua.cache_driver', 'array'))->forget('lingua_agent_tick_lock');
    // The opportunistic warm tick should fire only when async is opted in
    // (LINGUA_ASYNC=true) on a non-sync queue — which implies a real worker is
    // running to drain it. Standalone + a provider key keeps the package
    // "configured". Gateway mode is covered separately (it must NOT dispatch).
    config(['lingua.mode' => 'standalone', 'lingua.gemini_api_key' => 'test-key']);
    config(['lingua.async' => true, 'queue.default' => 'database']);
    config(['lingua.agent.enabled' => true, 'lingua.agent.tick_enabled' => true, 'lingua.agent.tick_interval_minutes' => 30]);
});

test('opportunistic tick dispatches a bounded warm once per interval', function () {
    Bus::fake();

    fireTick();                       // first GET 200 after the window → wins the lock
    Bus::assertDispatched(WarmAllPagesJob::class, 1);

    fireTick();                       // within the interval → throttled
    Bus::assertDispatched(WarmAllPagesJob::class, 1);
});

test('tick does not fire on non-200 or non-GET', function () {
    Bus::fake();
    fireTick(302);
    fireTick(500);
    fireTick(200, 'POST');
    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('tick does not fire when the agent is disabled', function () {
    config(['lingua.agent.tick_enabled' => false]);
    Bus::fake();
    fireTick();
    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('tick does not fire when LinguaLayer is unconfigured', function () {
    config(['lingua.mode' => 'standalone', 'lingua.gemini_api_key' => '', 'lingua.gateway.license_key' => '',
        'lingua.openai.api_key' => '', 'lingua.openai.base_url' => 'https://api.openai.com/v1']);
    Bus::fake();
    fireTick();
    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('tick does NOT dispatch in gateway mode even on a non-sync queue (no local worker)', function () {
    // Regression: Laravel 11/12 default queue.default=database flipped gateway
    // clients into "async", dispatching a WarmAllPagesJob that no worker ever
    // drained → dead rows piling up in the host's jobs table.
    config(['lingua.mode' => 'gateway', 'lingua.gateway.license_key' => 'LL-TEST-KEY', 'lingua.async' => true]);
    Bus::fake();
    fireTick();
    Bus::assertNotDispatched(WarmAllPagesJob::class);
});

test('tick does NOT dispatch when async is not opted in (LINGUA_ASYNC=false)', function () {
    config(['lingua.async' => false]);
    Bus::fake();
    fireTick();
    Bus::assertNotDispatched(WarmAllPagesJob::class);
});
