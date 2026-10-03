@php
    $language = strtolower(preg_split('/[_-]/', app()->getLocale())[0]);
    $rtl = in_array($language, ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('agentic-actions::oauth.title', ['client' => $client, 'app' => $app]) }}</title>
    <style>
        :root { color-scheme: light dark; --text: #1a1a1a; --muted: #5c5c5c; --line: #d9d9d9; --page: #f6f6f6; --card: #ffffff; --accent: #1a1a1a; --accent-text: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --text: #ededed; --muted: #a3a3a3; --line: #3a3a3a; --page: #121212; --card: #1c1c1c; --accent: #ededed; --accent-text: #121212; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--page); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        h1 { margin: 0 0 16px; font-size: 1.25rem; line-height: 1.35; overflow-wrap: anywhere; }
        p { margin: 0 0 12px; overflow-wrap: anywhere; }
        ul { margin: 0 0 16px; padding-inline-start: 20px; }
        li { margin-bottom: 4px; }
        .muted { color: var(--muted); font-size: 0.9375rem; }
        .trust { border-top: 1px solid var(--line); padding-top: 12px; margin-top: 16px; }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        form { flex: 1; margin: 0; }
        button { width: 100%; padding: 10px 16px; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: var(--text); font: inherit; cursor: pointer; }
        button.allow { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
    </style>
</head>
<body>
<main>
    <h1>{{ __('agentic-actions::oauth.title', ['client' => $client, 'app' => $app]) }}</h1>
    <p>{{ __('agentic-actions::oauth.can', ['client' => $client]) }}</p>
    <ul>
        @foreach ($abilities as $ability)
            <li>{{ $ability }}</li>
        @endforeach
    </ul>
    @if ($tenant !== null)
        <p>{{ __('agentic-actions::oauth.tenant', ['tenant' => $tenant]) }}</p>
    @endif
    <p>{{ __($redirect['local'] ? 'agentic-actions::oauth.local' : 'agentic-actions::oauth.redirect', ['host' => $redirect['host']]) }}</p>
    <p class="muted">{{ __('agentic-actions::oauth.person', ['person' => $person]) }}</p>
    <p class="muted trust">{{ __('agentic-actions::oauth.trust', ['client' => $client]) }}</p>
    <div class="actions">
        <form method="POST" action="{{ $deny['url'] }}">
            @foreach ($deny['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit">{{ __('agentic-actions::oauth.deny') }}</button>
        </form>
        <form method="POST" action="{{ $approve['url'] }}">
            @foreach ($approve['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="allow">{{ __('agentic-actions::oauth.allow') }}</button>
        </form>
    </div>
</main>
</body>
</html>
