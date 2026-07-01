<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LinguaLayer — Quality Dashboard</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #f5f5f7; color: #1d1d1f; padding: 2rem; }
    h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: .25rem; }
    .subtitle { color: #666; font-size: .9rem; margin-bottom: 2rem; }
    .cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; }
    .card { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; flex: 1; min-width: 160px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .card .label { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #888; }
    .card .value { font-size: 2rem; font-weight: 700; margin-top: .25rem; }
    .card .value.good { color: #16a34a; }
    .card .value.warn { color: #d97706; }
    .card .value.bad  { color: #dc2626; }
    .by-lang { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
               box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 2rem; }
    .by-lang h2 { font-size: 1rem; margin-bottom: .75rem; }
    .lang-row { display: flex; justify-content: space-between; padding: .4rem 0;
                border-bottom: 1px solid #f0f0f0; font-size: .9rem; }
    .lang-row:last-child { border-bottom: none; }
    .score-badge { font-weight: 600; padding: 2px 8px; border-radius: 99px; font-size: .8rem; }
    .score-high { background: #dcfce7; color: #16a34a; }
    .score-mid  { background: #fef9c3; color: #a16207; }
    .score-low  { background: #fee2e2; color: #dc2626; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px;
            overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    th { background: #fafafa; text-align: left; padding: .65rem 1rem; font-size: .75rem;
         text-transform: uppercase; letter-spacing: .05em; color: #666; border-bottom: 1px solid #eee; }
    td { padding: .65rem 1rem; font-size: .85rem; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    .issues { color: #dc2626; font-size: .78rem; margin-top: .2rem; }
    .empty { text-align: center; padding: 3rem; color: #999; }
    .section-h { font-size: 1.15rem; font-weight: 700; margin: 2.5rem 0 1rem; padding-top: 1rem;
                 border-top: 2px solid #e5e5ea; }
    .section-h:first-of-type { border-top: none; padding-top: 0; }
    .lang-bar { display: flex; gap: 8px; align-items: center; padding: 8px 0;
                border-bottom: 1px solid #f0f0f0; font-size: .9rem; }
    .lang-bar:last-child { border-bottom: none; }
    .lang-bar .name { width: 60px; font-weight: 600; }
    .lang-bar .bar { flex: 1; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; }
    .lang-bar .bar-fill { height: 100%; background: linear-gradient(90deg,#4f46e5,#7c3aed); border-radius: 4px; }
    .lang-bar .count { width: 60px; text-align: right; color: #666; font-variant-numeric: tabular-nums; }
    .small-table { font-size: .82rem; }
    .small-table td { padding: .45rem .8rem; }
    .small-table .src { color: #444; }
    .small-table .dst { color: #1d1d1f; font-weight: 500; }
    .meta-tag { display: inline-block; font-size: .7rem; padding: 1px 6px; border-radius: 4px;
                background: #ede9fe; color: #4f46e5; margin-left: 6px; vertical-align: middle; }

    /* ═══════ Agent panel (Fase 5) ═══════ */
    .agent-panel { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; margin-bottom: 2rem; }
    @media (max-width: 900px) { .agent-panel { grid-template-columns: 1fr; } }
    .agent-card { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
                  box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .agent-card h2 { font-size: 1.05rem; margin-bottom: .75rem; display: flex; align-items: center; gap: 8px; }
    .agent-status-active   { display: inline-block; font-size:.7rem; padding:2px 8px; border-radius:99px;
                             background:#dcfce7; color:#16a34a; font-weight:600; }
    .agent-status-disabled { display: inline-block; font-size:.7rem; padding:2px 8px; border-radius:99px;
                             background:#fee2e2; color:#dc2626; font-weight:600; }
    .agent-meta { font-size:.78rem; color:#666; margin-bottom: 1rem; }
    .agent-meta span { margin-right: 1rem; }
    .lang-progress { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .lang-progress:last-child { border-bottom: none; }
    .lang-progress-head { display: flex; justify-content: space-between;
                          align-items: center; margin-bottom: 6px; font-size: .92rem; }
    .lang-progress-head .lang-name { font-weight: 600; }
    .lang-progress-head .pct { font-variant-numeric: tabular-nums; color: #4f46e5; font-weight: 600; }
    .lang-progress-bar { height: 10px; background: #f0f0f0; border-radius: 5px; overflow: hidden; }
    .lang-progress-bar-fill { height: 100%; background: linear-gradient(90deg,#4f46e5,#7c3aed);
                              border-radius: 5px; transition: width .4s ease; }
    .lang-progress-bar-fill.completed { background: linear-gradient(90deg,#16a34a,#22c55e); }
    .lang-progress-bar-fill.error     { background: linear-gradient(90deg,#dc2626,#f87171); }
    .lang-progress-foot { display: flex; justify-content: space-between;
                          font-size: .76rem; color: #888; margin-top: 4px; }
    .agent-overall { background: #f8f9fc; padding: 12px 14px; border-radius: 8px;
                     margin-top: 1rem; font-size: .9rem; }
    .agent-overall .total { font-size: 1.4rem; font-weight: 700; color: #1d1d1f; }
    .notifications { max-height: 320px; overflow-y: auto; }
    .notif { padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: .85rem; }
    .notif:last-child { border-bottom: none; }
    .notif .icon { margin-right: 6px; }
    .notif .when { font-size: .72rem; color: #999; display: block; margin-top: 2px; }
    .completion-banner {
        background: linear-gradient(135deg,#16a34a,#22c55e); color: #fff;
        padding: 1.5rem 1.75rem; border-radius: 14px; margin-bottom: 2rem;
        box-shadow: 0 4px 18px rgba(22,163,74,.35);
        position: relative;
    }
    .completion-banner h2 { font-size: 1.4rem; margin-bottom: .5rem; }
    .completion-banner ul { margin: .5rem 0 .75rem 1.5rem; }
    .completion-banner small { opacity: .9; }
    .completion-banner .dismiss {
        position: absolute; top: 12px; right: 14px; background: rgba(255,255,255,.2);
        color: #fff; border: none; padding: 4px 10px; border-radius: 6px;
        cursor: pointer; font-size: .75rem;
    }

    /* ═══════ Action panel (Pilar 5.13) ═══════ */
    .action-panel { background: #fff; border-radius: 12px; padding: 1.1rem 1.3rem;
                    box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 1.5rem; }
    .action-panel h2 { font-size: 1rem; margin-bottom: .8rem; }
    .action-panel .btns { display: flex; flex-wrap: wrap; gap: 8px; }
    .lingua-btn {
        font-family: inherit; font-size: .82rem; font-weight: 600;
        padding: 8px 14px; border-radius: 8px; border: 1px solid transparent;
        cursor: pointer; transition: transform .08s, box-shadow .15s;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .lingua-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.12); }
    .lingua-btn:active { transform: translateY(0); }
    .lingua-btn:disabled { opacity: .55; cursor: wait; transform: none; box-shadow: none; }
    .lingua-btn.primary { background: #4f46e5; color: #fff; }
    .lingua-btn.primary:hover { background: #4338ca; }
    .lingua-btn.success { background: #16a34a; color: #fff; }
    .lingua-btn.success:hover { background: #15803d; }
    .lingua-btn.warning { background: #d97706; color: #fff; }
    .lingua-btn.warning:hover { background: #b45309; }
    .lingua-btn.danger { background: #dc2626; color: #fff; }
    .lingua-btn.danger:hover { background: #b91c1c; }
    .lingua-btn.ghost { background: #f3f4f6; color: #1d1d1f; border-color: #d1d5db; }
    .lingua-btn.ghost:hover { background: #e5e7eb; }

    /* Toast notifications */
    .toast-host {
        position: fixed; top: 16px; right: 16px; z-index: 9999;
        display: flex; flex-direction: column; gap: 8px; max-width: 380px;
    }
    .toast {
        background: #1f2937; color: #fff; padding: 10px 14px; border-radius: 8px;
        font-size: .85rem; box-shadow: 0 4px 14px rgba(0,0,0,.25);
        animation: toastSlide .25s ease-out;
        border-left: 3px solid #4f46e5;
    }
    .toast.success { border-left-color: #16a34a; }
    .toast.error   { border-left-color: #dc2626; }
    @keyframes toastSlide { from {opacity:0;transform:translateX(40px);} to {opacity:1;transform:translateX(0);} }
</style>
</head>
<body>

<h1>LinguaLayer Dashboard</h1>
<p class="subtitle">Configuration · Quality scoring · Persistent translations · Cache stats</p>

<div class="toast-host" id="toast-host"></div>

{{-- ═══════════════════ Action buttons (Pilar 5.13) ═══════════════════ --}}
<div class="action-panel">
    <h2>⚙️ Agent controls</h2>
    <div class="btns">
        <button class="lingua-btn primary" data-action="scan">
            🔍 Scan & translate now
        </button>
        <button class="lingua-btn warning" data-action="check-changes">
            ✏️ Check for changes
        </button>
        <button class="lingua-btn success" data-action="quality-check">
            ⭐ Re-evaluate low-score
        </button>
        @if($agentState && $agentState['enabled'])
            <button class="lingua-btn ghost" data-action="disable">
                ⏸ Disable agent
            </button>
        @else
            <button class="lingua-btn primary" data-action="enable">
                ▶️ Enable agent
            </button>
        @endif
        <button class="lingua-btn danger" data-action="clear-cache" data-confirm="¿Borrar TODA la cache de traducciones? Las próximas visitas re-traducirán.">
            🗑 Clear cache
        </button>
        <button class="lingua-btn ghost" id="btn-refresh">
            🔄 Refresh now
        </button>
    </div>
    <p style="margin-top:.7rem;font-size:.75rem;color:#888">
        Las acciones se ejecutan automáticamente — mientras esta pestaña esté abierta el dashboard procesa la cola por sí solo.
        Cero terminal necesario.
    </p>
    <div id="queue-stats" style="margin-top:.5rem;font-size:.78rem;color:#4f46e5;display:none">
        <span id="queue-state">⏳ Auto-processing queue…</span>
        <span style="margin-left:.7rem;color:#888">processed=<strong id="queue-processed">0</strong> · failed=<strong id="queue-failed">0</strong> · last=<span id="queue-last">—</span></span>
    </div>
</div>

{{-- ═══════════════════ Agent progress (Fase 5) ═══════════════════ --}}
<div id="completion-banner-host"></div>

<h2 class="section-h" style="margin-top:1rem">🤖 Lingua Agent — Translation Progress</h2>

<div class="agent-meta">
    @if($agentState && $agentState['enabled'])
        <span class="agent-status-active">ACTIVE</span>
    @elseif($agentState)
        <span class="agent-status-disabled">DISABLED</span>
    @else
        <span class="agent-status-disabled">NOT INSTALLED — run migrate</span>
    @endif
    @if($agentState)
        <span>Last scan: <strong id="agent-last-scan">{{ $agentState['last_full_scan_at'] ?? 'never' }}</strong></span>
        <span>Pages monitored: <strong id="agent-pages-known">{{ $agentState['pages_known'] }}</strong></span>
    @endif
</div>

<div class="agent-panel">
    <div class="agent-card">
        <h2>Per-language progress</h2>
        <div id="lang-progress-list">
            @forelse($agentProgress as $lang => $info)
                @php
                    $cls = $info['status'] === 'completed' ? 'completed' : ($info['status'] === 'error' ? 'error' : '');
                @endphp
                <div class="lang-progress" data-lang="{{ $lang }}">
                    <div class="lang-progress-head">
                        <span class="lang-name">{{ $lang }}</span>
                        <span class="pct" data-pct>{{ number_format($info['percentage'], 1) }}%</span>
                    </div>
                    <div class="lang-progress-bar">
                        <div class="lang-progress-bar-fill {{ $cls }}"
                             data-fill
                             style="width: {{ $info['percentage'] }}%"></div>
                    </div>
                    <div class="lang-progress-foot">
                        <span data-pages>{{ $info['pages_translated'] }}/{{ $info['pages_total'] }} pages</span>
                        <span data-eta>{{ $info['eta_human'] ? '⏱ ETA ' . $info['eta_human'] : ' ' }}</span>
                        <span data-status>{{ $info['status'] }}</span>
                    </div>
                </div>
            @empty
                <p style="color:#888;font-size:.85rem">
                    No active runs. Trigger one with
                    <code>php artisan lingua:agent:scan --force</code>.
                </p>
            @endforelse
        </div>

        <div class="agent-overall">
            <div>Total translations: <strong id="overall-completed">{{ $agentOverall['completed_translations'] }}</strong> / <strong id="overall-total">{{ $agentOverall['total_translations'] }}</strong></div>
            <div class="total" id="overall-percentage">{{ number_format($agentOverall['percentage'], 1) }}%</div>
            <div style="font-size:.78rem;color:#666;margin-top:4px">
                Languages completed: <strong id="overall-langs-completed">{{ $agentOverall['languages_completed'] }}</strong>
                / <span id="overall-langs-target">{{ $agentOverall['languages_target'] }}</span>
            </div>
        </div>
    </div>

    <div class="agent-card">
        <h2>🔔 Recent activity</h2>
        <div class="notifications" id="agent-notifications">
            @forelse($agentEvents as $event)
                @php
                    $icon = match ($event['type'] ?? '') {
                        'started'         => '🔄',
                        'completed'       => '✅',
                        'all_completed'   => '🎉',
                        'discovery'       => '🔍',
                        'change_detected' => '✏️',
                        default           => '•',
                    };
                @endphp
                <div class="notif">
                    <span class="icon">{{ $icon }}</span>
                    @switch($event['type'] ?? '')
                        @case('started')
                            Iniciado: <strong>{{ $event['language'] ?? '?' }}</strong> ({{ $event['pages'] ?? 0 }} páginas)
                            @break
                        @case('completed')
                            Completado: <strong>{{ $event['language'] ?? '?' }}</strong>
                            @break
                        @case('all_completed')
                            🎉 <strong>Todos los idiomas completos</strong>
                            @break
                        @case('discovery')
                            Discovery: {{ $event['pages'] ?? 0 }} páginas
                            @break
                        @case('change_detected')
                            Cambios detectados: {{ $event['pages'] ?? 0 }} páginas
                            @break
                        @default
                            {{ $event['type'] ?? 'event' }}
                    @endswitch
                    <span class="when">{{ $event['timestamp'] ?? '' }}</span>
                </div>
            @empty
                <p style="color:#888;font-size:.85rem">No activity yet.</p>
            @endforelse
        </div>
    </div>
</div>

{{-- ═══════════════════ Configuration ═══════════════════ --}}
<h2 class="section-h" style="margin-top:1rem">Configuration</h2>

@php
  $modeBadge = match ($mode ?? 'unconfigured') {
      'standalone'   => ['label' => 'STANDALONE', 'class' => 'score-mid'],
      'gateway'      => ['label' => 'GATEWAY',    'class' => 'score-high'],
      default        => ['label' => 'UNCONFIGURED', 'class' => 'score-low'],
  };
@endphp

<div class="cards">
    <div class="card">
        <div class="label">Active mode</div>
        <div class="value" style="font-size:1.2rem">
          <span class="score-badge {{ $modeBadge['class'] }}">{{ $modeBadge['label'] }}</span>
        </div>
    </div>

    @if($mode === 'standalone')
        <div class="card">
            <div class="label">Gemini key</div>
            <div class="value good" style="font-size:1.1rem">configured</div>
        </div>
        <div class="card">
            <div class="label">Model</div>
            <div class="value" style="font-size:1.1rem">{{ config('lingua.gemini_model') }}</div>
        </div>
    @elseif($mode === 'gateway' && !empty($gateway))
        <div class="card">
            <div class="label">Gateway URL</div>
            <div class="value" style="font-size:.95rem;word-break:break-all">{{ $gateway['url'] }}</div>
        </div>
        <div class="card">
            <div class="label">License</div>
            <div class="value {{ $gateway['verified'] ? 'good' : 'bad' }}" style="font-size:1.1rem">
                {{ $gateway['verified'] ? 'verified' : 'unreachable' }}
            </div>
        </div>
        @if(!empty($gateway['usage']))
            @php $u = $gateway['usage']; @endphp
            <div class="card">
                <div class="label">Plan</div>
                <div class="value" style="font-size:1.1rem">{{ $u['plan'] ?? '—' }}</div>
                <small style="color:#666">resets {{ $u['reset_at'] ?? '—' }}</small>
            </div>
        @endif
    @endif
</div>

@if($mode === 'gateway' && !empty($gateway['usage']['savings_summary']))
    @php $s = $gateway['usage']['savings_summary']; @endphp
    <div class="by-lang" style="background:linear-gradient(135deg,#dcfce7 0%,#fef9c3 100%)">
        <h2>⭐ Network effect savings</h2>
        <div class="cards" style="margin-top:8px">
            <div class="card">
                <div class="label">Words saved (network)</div>
                <div class="value good">{{ number_format($s['total_words_saved_by_network'] ?? 0) }}</div>
            </div>
            <div class="card">
                <div class="label">Words saved (own repeat)</div>
                <div class="value">{{ number_format($s['total_words_saved_by_repetition'] ?? 0) }}</div>
            </div>
            <div class="card">
                <div class="label">Total saved this month</div>
                <div class="value good">{{ number_format($s['total_words_saved'] ?? 0) }}</div>
                <small>{{ $s['savings_percentage'] ?? 0 }}% off the no-network price</small>
            </div>
        </div>
        <p style="margin-top:14px;font-size:.9rem;color:#1d1d1f">
            {{ $s['message'] ?? '' }}
            <br>
            <small style="color:#666">Every fresh translation you make also benefits the network — your contributions become free for other clients.</small>
        </p>
    </div>

    @if(!empty($gateway['usage']['billing_breakdown']))
        @php $b = $gateway['usage']['billing_breakdown']; @endphp
        <div class="by-lang">
            <h2>Billing breakdown</h2>
            <div class="lang-row"><span>Fresh translations</span><span>{{ number_format($b['fresh_translations']['count'] ?? 0) }} · {{ number_format($b['fresh_translations']['words_charged'] ?? 0) }} words charged</span></div>
            <div class="lang-row"><span>Free via network effect</span><span style="color:#16a34a">{{ number_format($b['network_effect_free']['count'] ?? 0) }} · {{ number_format($b['network_effect_free']['translations_free'] ?? 0) }} free</span></div>
            <div class="lang-row"><span>Own repetitions (50% off)</span><span>{{ number_format($b['own_repetitions']['count'] ?? 0) }} · {{ number_format($b['own_repetitions']['words_charged_half'] ?? 0) }} half-priced</span></div>
        </div>
    @endif
@endif

<h2 class="section-h">Quality scoring</h2>
<p style="color:#888;font-size:.85rem;margin-bottom:1rem">Auto-scored translation samples — {{ $stats['count'] }} total</p>

<div class="cards">
    <div class="card">
        <div class="label">Total scored</div>
        <div class="value">{{ $stats['count'] }}</div>
    </div>
    <div class="card">
        <div class="label">Average score</div>
        @php $avg = $stats['average']; @endphp
        <div class="value {{ $avg >= 8 ? 'good' : ($avg >= 6 ? 'warn' : 'bad') }}">
            {{ $avg > 0 ? $avg : '—' }}
        </div>
    </div>
    <div class="card">
        <div class="label">Low score (&lt;7)</div>
        <div class="value {{ $stats['low_count'] > 0 ? 'warn' : 'good' }}">
            {{ $stats['low_count'] }}
        </div>
    </div>
</div>

@if(!empty($stats['by_lang']))
<div class="by-lang">
    <h2>Score by language</h2>
    @foreach($stats['by_lang'] as $lang => $ls)
        @php $avg = $ls['average']; @endphp
        <div class="lang-row">
            <span>{{ $lang }} <small style="color:#999">({{ $ls['count'] }} samples)</small></span>
            <span class="score-badge {{ $avg >= 8 ? 'score-high' : ($avg >= 6 ? 'score-mid' : 'score-low') }}">
                {{ $avg }} / 10
            </span>
        </div>
    @endforeach
</div>
@endif

@if(count($index) > 0)
<table>
    <thead>
        <tr>
            <th>Score</th>
            <th>Lang</th>
            <th>Original (truncated)</th>
            <th>Translation (truncated)</th>
            <th>Scored at</th>
        </tr>
    </thead>
    <tbody>
        @foreach($index as $entry)
            @php $s = $entry['score']; @endphp
            <tr>
                <td>
                    <span class="score-badge {{ $s >= 8 ? 'score-high' : ($s >= 6 ? 'score-mid' : 'score-low') }}">
                        {{ $s }}
                    </span>
                </td>
                <td>{{ $entry['lang'] }}</td>
                <td>
                    {{ $entry['original'] }}
                    @if(!empty($entry['issues']))
                        <div class="issues">⚠ {{ implode(', ', $entry['issues']) }}</div>
                    @endif
                </td>
                <td>{{ $entry['translated'] }}</td>
                <td style="color:#999;font-size:.78rem;white-space:nowrap">{{ $entry['scored_at'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@else
<div class="empty">
    No scored translations yet.<br>
    <small>Enable <code>LINGUA_AUTO_SCORE=true</code> in your .env and wait for page translations to run.</small>
</div>
@endif

{{-- ═══════════════════ Persistent translations ═══════════════════ --}}
<h2 class="section-h">Translations <span class="meta-tag">BD persistente</span></h2>

@if($storeStats['total_active'] === 0 && $storeStats['total_obsolete'] === 0)
    <div class="empty">
        Persistent store empty.<br>
        <small>Run <code>php artisan migrate</code> if the table doesn't exist, then translate any page.<br>
        Translations are saved to <code>lingua_translations</code> automatically.</small>
    </div>
@else

<div class="cards">
    <div class="card">
        <div class="label">Active</div>
        <div class="value good">{{ number_format($storeStats['total_active']) }}</div>
    </div>
    <div class="card">
        <div class="label">Obsolete</div>
        <div class="value {{ $storeStats['total_obsolete'] > 0 ? 'warn' : '' }}">
            {{ number_format($storeStats['total_obsolete']) }}
        </div>
    </div>
    <div class="card">
        <div class="label">Avg quality</div>
        <div class="value">
            {{ $storeStats['avg_score'] ?? '—' }}
        </div>
    </div>
</div>

@if(!empty($storeStats['by_language']))
<div class="by-lang">
    <h2>By language</h2>
    @php $maxLangCount = max($storeStats['by_language']); @endphp
    @foreach($storeStats['by_language'] as $lang => $count)
        <div class="lang-bar">
            <span class="name">{{ $lang }}</span>
            <span class="bar"><span class="bar-fill" style="width: {{ ($count / $maxLangCount) * 100 }}%"></span></span>
            <span class="count">{{ number_format($count) }}</span>
        </div>
    @endforeach
</div>
@endif

@if(count($storeStats['top_used']) > 0)
<h3 style="font-size:1rem;margin:1.25rem 0 .75rem">Top used translations</h3>
<table class="small-table">
    <thead>
        <tr>
            <th style="width:60px">Lang</th>
            <th>Source</th>
            <th>Translation</th>
            <th style="width:80px;text-align:right">Used</th>
        </tr>
    </thead>
    <tbody>
        @foreach($storeStats['top_used'] as $t)
            <tr>
                <td>{{ $t->target_lang }}</td>
                <td class="src">{{ \Illuminate\Support\Str::limit($t->source_text, 80) }}</td>
                <td class="dst">{{ \Illuminate\Support\Str::limit($t->translated_text, 80) }}</td>
                <td style="text-align:right;font-variant-numeric:tabular-nums">{{ number_format($t->times_used) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

@if($recent->isNotEmpty())
<h3 style="font-size:1rem;margin:1.5rem 0 .75rem">Recently added (last 50)</h3>
<table class="small-table">
    <thead>
        <tr>
            <th style="width:60px">Lang</th>
            <th>Source</th>
            <th>Translation</th>
            <th style="width:130px">Added</th>
        </tr>
    </thead>
    <tbody>
        @foreach($recent as $t)
            <tr>
                <td>{{ $t->target_lang }}</td>
                <td class="src">{{ \Illuminate\Support\Str::limit($t->source_text, 70) }}</td>
                <td class="dst">{{ \Illuminate\Support\Str::limit($t->translated_text, 70) }}</td>
                <td style="color:#999;font-size:.78rem;white-space:nowrap">{{ $t->created_at?->diffForHumans() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

@endif

{{-- ═══════════════════ Storage / cache stats ═══════════════════ --}}
<h2 class="section-h">Storage</h2>

<div class="cards">
    <div class="card">
        <div class="label">Cache coverage</div>
        <div class="value {{ $cacheCounters['coverage_pct'] >= 80 ? 'good' : ($cacheCounters['coverage_pct'] >= 50 ? 'warn' : 'bad') }}">
            {{ $cacheCounters['coverage_pct'] }}%
        </div>
    </div>
    <div class="card">
        <div class="label">Cache hits (lifetime)</div>
        <div class="value">{{ number_format($cacheCounters['hits_total']) }}</div>
    </div>
    <div class="card">
        <div class="label">Gemini calls (lifetime)</div>
        <div class="value">{{ number_format($cacheCounters['calls_total']) }}</div>
    </div>
    <div class="card">
        <div class="label">Pages cached</div>
        <div class="value">{{ number_format($cacheCounters['pages_total']) }}</div>
    </div>
</div>

<div class="by-lang">
    <h2>Activity</h2>
    <div class="lang-row">
        <span>Fragments cached (lifetime)</span>
        <span>{{ number_format($cacheCounters['fragments_total']) }}</span>
    </div>
    <div class="lang-row">
        <span>Last warm</span>
        <span style="color:#666">{{ $cacheCounters['last_warm'] ?: 'never' }}</span>
    </div>
    <div class="lang-row">
        <span>Estimated Gemini calls saved</span>
        <span style="color:#16a34a;font-weight:600">≈ {{ number_format($cacheCounters['hits_total']) }}</span>
    </div>
</div>

{{-- ═══════════════════ Pilar 5.11 — auto-refresh JS ═══════════════════ --}}
<script>
(function () {
    const SECRET   = @json((string) request()->query('key', ''));
    const ENDPOINT = '{{ route('lingua.quality.progress') }}' + (SECRET ? '?key=' + encodeURIComponent(SECRET) : '');
    const INTERVAL = 5000;

    let completionShown = false;

    function findOrCreateRow(lang) {
        const list = document.getElementById('lang-progress-list');
        if (!list) return null;
        let row = list.querySelector(`[data-lang="${lang}"]`);
        if (row) return row;

        row = document.createElement('div');
        row.className = 'lang-progress';
        row.setAttribute('data-lang', lang);
        row.innerHTML = `
            <div class="lang-progress-head">
                <span class="lang-name">${lang}</span>
                <span class="pct" data-pct>0.0%</span>
            </div>
            <div class="lang-progress-bar">
                <div class="lang-progress-bar-fill" data-fill style="width: 0%"></div>
            </div>
            <div class="lang-progress-foot">
                <span data-pages>0/0 pages</span>
                <span data-eta></span>
                <span data-status>idle</span>
            </div>`;
        list.appendChild(row);
        return row;
    }

    function applyLanguage(row, info) {
        if (!row) return;
        const fill = row.querySelector('[data-fill]');
        if (fill) {
            fill.style.width = (info.percentage || 0) + '%';
            fill.classList.toggle('completed', info.status === 'completed');
            fill.classList.toggle('error',     info.status === 'error');
        }
        row.querySelector('[data-pct]').textContent     = (info.percentage || 0).toFixed(1) + '%';
        row.querySelector('[data-pages]').textContent   = (info.pages_translated || 0) + '/' + (info.pages_total || 0) + ' pages';
        row.querySelector('[data-eta]').textContent     = info.eta_human ? '⏱ ETA ' + info.eta_human : '';
        row.querySelector('[data-status]').textContent  = info.status || 'idle';
    }

    function showCompletionBanner(data) {
        if (completionShown) return;
        const host = document.getElementById('completion-banner-host');
        if (!host) return;
        const langs = Object.entries(data.languages || {})
            .map(([lang, info]) => `<li><strong>${lang}</strong>: ${info.pages_total || 0} páginas</li>`)
            .join('');
        host.innerHTML = `
            <div class="completion-banner">
                <button class="dismiss" onclick="this.parentElement.remove()">Dismiss</button>
                <h2>🎉 TRANSLATION COMPLETE</h2>
                <p>Tu app está disponible en ${Object.keys(data.languages || {}).length} idioma(s):</p>
                <ul>${langs}</ul>
                <small>Total: ${data.overall.total_translations || 0} páginas en cache</small>
            </div>`;
        completionShown = true;

        if (window.Notification && Notification.permission === 'granted') {
            new Notification('LinguaLayer', {
                body: '¡Traducción completa! Tu app está lista.'
            });
        }
    }

    // Self-driving queue: fire-and-forget POST to process-queue every tick.
    // Runs in parallel with the progress poll so the bars advance as jobs
    // complete. Inline counter ticks the badge in the action panel.
    let totalProcessed = 0;
    let totalFailed    = 0;
    async function processQueue() {
        try {
            const url = '{{ url('lingua/quality/action/process-queue') }}' + (SECRET ? '?key=' + encodeURIComponent(SECRET) : '');
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (typeof data.processed === 'number') {
                totalProcessed += data.processed;
                totalFailed    += data.failed || 0;
                const stats = document.getElementById('queue-stats');
                if (stats) stats.style.display = 'block';
                const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
                setVal('queue-processed', totalProcessed);
                setVal('queue-failed',    totalFailed);
                setVal('queue-last',      data.processed + ' job(s) in ' + (data.elapsed_ms || 0) + 'ms');
                const stateEl = document.getElementById('queue-state');
                if (stateEl) stateEl.textContent = data.processed > 0
                    ? '🔄 Processing queue…'
                    : '✓ Queue idle';
            }
        } catch (e) {
            // best-effort
        }
    }

    async function tick() {
        // Always drain the queue first so progress numbers reflect work done.
        processQueue();

        try {
            const res = await fetch(ENDPOINT, { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();

            // Per-language bars
            const langs = data.languages || {};
            for (const [lang, info] of Object.entries(langs)) {
                applyLanguage(findOrCreateRow(lang), info);
            }

            // Overall counters
            const o = data.overall || {};
            const setText = (id, v) => {
                const el = document.getElementById(id);
                if (el) el.textContent = v;
            };
            setText('overall-completed',       o.completed_translations || 0);
            setText('overall-total',           o.total_translations || 0);
            setText('overall-percentage',      (o.percentage || 0).toFixed(1) + '%');
            setText('overall-langs-completed', o.languages_completed || 0);
            setText('overall-langs-target',    o.languages_target || 0);

            if (o.all_completed) showCompletionBanner(data);
        } catch (e) {
            // network blip — retry next tick
        }
    }

    // Ask for browser-level notification permission once
    if (window.Notification && Notification.permission === 'default') {
        try { Notification.requestPermission(); } catch (e) { /* ignored */ }
    }

    setInterval(tick, INTERVAL);
    tick();

    // ═══════ Action buttons (Pilar 5.13) ═══════
    function toast(message, kind) {
        const host = document.getElementById('toast-host');
        if (!host) { alert(message); return; }
        const t = document.createElement('div');
        t.className = 'toast ' + (kind || '');
        t.textContent = message;
        host.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 3500);
        setTimeout(() => t.remove(), 4000);
    }

    async function runAction(action, btn) {
        const url = '{{ url('lingua/quality/action') }}/' + action + (SECRET ? '?key=' + encodeURIComponent(SECRET) : '');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ ' + (btn.textContent || '').trim().slice(2);
        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok !== false) {
                toast(data.message || 'OK', 'success');
                tick(); // immediate refresh of progress
                // For toggle actions, reload to swap the button
                if (action === 'enable' || action === 'disable') {
                    setTimeout(() => location.reload(), 800);
                }
            } else {
                toast(data.message || ('Error ' + res.status), 'error');
            }
        } catch (e) {
            toast('Network error: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }

    document.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.getAttribute('data-action');
            const confirmMsg = btn.getAttribute('data-confirm');
            if (confirmMsg && !confirm(confirmMsg)) return;
            runAction(action, btn);
        });
    });

    const refreshBtn = document.getElementById('btn-refresh');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            tick();
            toast('Refreshed.', 'success');
        });
    }
})();
</script>

</body>
</html>
