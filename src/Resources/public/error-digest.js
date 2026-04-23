/*!
 * hexis/error-digest-bundle — browser-side error capture client.
 *
 * Auto-installs on DOMContentLoaded when <script> tag has data-endpoint.
 * Captures:
 *   - `error` events (synchronous JS errors + resource load failures)
 *   - `unhandledrejection` events (async / promise rejections)
 *
 * Reports are POSTed as JSON to the ingest endpoint. Silent on failure —
 * never let telemetry take the page down.
 */
(function () {
    'use strict';

    var scriptEl = document.currentScript ||
        (function () {
            var s = document.querySelectorAll('script[data-endpoint]');
            return s.length ? s[s.length - 1] : null;
        })();

    if (!scriptEl) {
        return;
    }

    var endpoint = scriptEl.getAttribute('data-endpoint');
    if (!endpoint) {
        return;
    }

    var release = scriptEl.getAttribute('data-release') || null;
    var userRef = scriptEl.getAttribute('data-user') || null;
    var maxPerPage = parseInt(scriptEl.getAttribute('data-max-per-page') || '50', 10);
    var dedupWindowMs = parseInt(scriptEl.getAttribute('data-dedup-window-ms') || '5000', 10);

    var sentCount = 0;
    var recentSignatures = Object.create(null);

    function shouldSend(sig) {
        if (sentCount >= maxPerPage) {
            return false;
        }
        var now = Date.now();
        var last = recentSignatures[sig];
        if (last && (now - last) < dedupWindowMs) {
            return false;
        }
        recentSignatures[sig] = now;
        sentCount += 1;
        return true;
    }

    function signature(payload) {
        return [
            payload.type || 'err',
            payload.source || '',
            payload.line || 0,
            (payload.message || '').slice(0, 160)
        ].join('|');
    }

    function post(payload) {
        var body = JSON.stringify(payload);

        // sendBeacon survives the page unload that pagehide/beforeunload can trigger.
        // Fall back to fetch keepalive for browsers without sendBeacon.
        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(endpoint, blob)) {
                    return;
                }
            } catch (_) { /* fall through */ }
        }

        try {
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                credentials: 'same-origin',
                keepalive: true,
                mode: 'cors'
            }).catch(function () { /* swallow */ });
        } catch (_) { /* swallow */ }
    }

    function report(payload) {
        payload.url = location.href;
        payload.user_agent = navigator.userAgent;
        if (release) { payload.release = release; }
        if (userRef) { payload.user = userRef; }

        var sig = signature(payload);
        if (!shouldSend(sig)) {
            return;
        }

        post(payload);
    }

    window.addEventListener('error', function (event) {
        // event.error is null for cross-origin errors (opaque "Script error.") — still worth reporting
        var err = event.error;
        var message = (err && err.message) || event.message || 'Script error';
        var stack = err && err.stack ? String(err.stack) : null;
        var type = err && err.name ? err.name : null;

        report({
            message: String(message),
            type: type,
            source: event.filename || null,
            line: event.lineno || null,
            column: event.colno || null,
            stack: stack
        });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason;
        var message;
        var stack = null;
        var type = null;

        if (reason instanceof Error) {
            message = reason.message;
            stack = reason.stack ? String(reason.stack) : null;
            type = reason.name;
        } else if (typeof reason === 'string') {
            message = reason;
        } else {
            try { message = JSON.stringify(reason); } catch (_) { message = String(reason); }
        }

        report({
            message: 'Unhandled rejection: ' + String(message || 'unknown'),
            type: type || 'UnhandledRejection',
            source: null,
            line: null,
            column: null,
            stack: stack
        });
    });

    // Expose a manual entry point for apps that want to forward their own caught errors.
    window.errorDigest = window.errorDigest || {
        capture: function (error, extra) {
            extra = extra || {};
            var message = (error && error.message) || String(error);
            var stack = (error && error.stack) ? String(error.stack) : null;
            report({
                message: message,
                type: (error && error.name) || extra.type || 'ManualCapture',
                source: extra.source || null,
                line: extra.line || null,
                column: extra.column || null,
                stack: stack
            });
        }
    };
})();
