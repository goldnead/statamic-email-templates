<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('email-templates::preview.page_title') }}</title>
    {{-- Standalone page (not statamic::layout): Statamic 6's CP is an Inertia/Vue
         SPA, so a raw @extends('statamic::layout') blade breaks at runtime
         (layout Vue components throw on missing globals). This focused preview
         renders its own minimal, theme-aware chrome instead. --}}
    <style>
        :root {
            --bg: #f1f5f9; --panel: #ffffff; --border: #e2e8f0; --text: #1e293b;
            --muted: #64748b; --primary: #155eef; --primary-text: #ffffff; --radius: 8px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f172a; --panel: #1e293b; --border: #334155; --text: #e2e8f0;
                --muted: #94a3b8; --primary: #3b82f6; --primary-text: #ffffff;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 14px; line-height: 1.5;
        }
        .wrap { max-width: 900px; margin: 0 auto; padding: 24px 20px 60px; }
        header.pv { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        header.pv h1 { font-size: 20px; font-weight: 700; margin: 0; }
        a.back { color: var(--muted); text-decoration: none; font-size: 13px; }
        a.back:hover { color: var(--text); }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; margin-bottom: 16px; }
        label.fld { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
        select, textarea {
            width: 100%; background: var(--panel); color: var(--text);
            border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px;
            font-family: inherit; font-size: 14px;
        }
        textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        details { margin-top: 14px; }
        summary { cursor: pointer; color: var(--muted); font-size: 13px; }
        .help { color: var(--muted); font-size: 12px; margin-top: 4px; }
        button.btn {
            margin-top: 14px; background: var(--primary); color: var(--primary-text);
            border: 0; border-radius: 6px; padding: 9px 16px; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        button.btn:hover { filter: brightness(1.05); }
        .subject { font-size: 14px; margin-bottom: 10px; }
        .subject b { font-weight: 600; }
        .err { color: #ef4444; font-size: 13px; margin-bottom: 10px; }
        .hidden { display: none; }
        iframe {
            width: 100%; min-height: 520px; border: 1px solid var(--border);
            border-radius: 6px; background: #fff;
        }
        .empty { color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="pv">
            <h1>{{ __('email-templates::preview.page_title') }}</h1>
            <a class="back" href="{{ cp_route('collections.show', \Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager::HANDLE) }}">&larr; {{ __('email-templates::preview.back') }}</a>
        </header>

        @if ($templates->isEmpty())
            <div class="card"><p class="empty">{{ __('email-templates::preview.empty') }}</p></div>
        @else
            <div class="card">
                <label class="fld" for="et-template">{{ __('email-templates::preview.select_template') }}</label>
                <select id="et-template">
                    @foreach ($templates as $t)
                        <option value="{{ $t['slug'] }}" @selected($t['slug'] === $selectedSlug)>{{ $t['title'] }} ({{ $t['slug'] }})</option>
                    @endforeach
                </select>

                <details>
                    <summary>{{ __('email-templates::preview.merge_data_label') }}</summary>
                    <textarea id="et-merge" rows="10">{{ $sampleDataJson }}</textarea>
                    <p class="help">{{ __('email-templates::preview.merge_data_help') }}</p>
                </details>

                <button id="et-refresh" class="btn" type="button">{{ __('email-templates::preview.refresh') }}</button>
            </div>

            <div class="card">
                <div class="subject"><b>{{ __('email-templates::preview.subject') }}:</b> <span id="et-subject" class="empty"></span></div>
                <div id="et-error" class="err hidden"></div>
                {{-- sandbox without allow-scripts: email markup cannot run JS in the CP;
                     allow-same-origin lets its styles render. --}}
                <iframe id="et-frame" title="{{ __('email-templates::preview.iframe_title') }}" sandbox="allow-same-origin"></iframe>
            </div>
        @endif
    </div>

    <script>
        (function () {
            var postUrl = @json($postUrl);
            var csrf = @json($csrf);
            var frame = document.getElementById('et-frame');
            var select = document.getElementById('et-template');
            var mergeEl = document.getElementById('et-merge');
            var subjectEl = document.getElementById('et-subject');
            var errorEl = document.getElementById('et-error');
            var refreshBtn = document.getElementById('et-refresh');
            if (!frame || !select) return;

            function showError(msg) { errorEl.textContent = msg; errorEl.classList.remove('hidden'); }

            function render() {
                errorEl.classList.add('hidden');
                var merge = {};
                if (mergeEl && mergeEl.value.trim()) {
                    try { merge = JSON.parse(mergeEl.value); }
                    catch (e) { showError(@json(__('email-templates::preview.invalid_json'))); return; }
                }
                fetch(postUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ template: select.value, merge_data: merge })
                }).then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                }).then(function (data) {
                    subjectEl.textContent = data.subject || '';
                    frame.srcdoc = data.body || '';
                }).catch(function (e) {
                    showError(@json(__('email-templates::preview.render_failed')) + ' (' + e.message + ')');
                });
            }

            refreshBtn && refreshBtn.addEventListener('click', render);
            select.addEventListener('change', render);
            if (select.value) render();
        })();
    </script>
</body>
</html>
