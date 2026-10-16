# hexis/error-digest-bundle

Captures application errors via a Monolog handler, deduplicates them by fingerprint into its own Doctrine-managed tables, sends a daily digest to admins, and provides a triage UI.

Built for when logs are a haystack and real errors go under the radar.

---

## What it does

- **Captures** every Monolog record at or above a configurable level (default: `warning`) from any channel.
- **Deduplicates** by fingerprint (exception class + file + line + normalized message) so the same error fires once, no matter how many times it occurs.
- **Rate-limits** within one process — 1000 occurrences of the same error in a second become a single occurrence row with accurate counters.
- **Stores** in its own `err_fingerprint` + `err_occurrence` tables on whichever Doctrine connection you point it at.
- **Scrubs** PII (passwords, tokens, bearer headers, JWTs, credit-card patterns, e-mail addresses, URL query strings) before writing.
- **Digests** daily via Mailer + optional Slack/Teams via Notifier. Sections: new, spiking, top, stale.
- **UI** at a configurable route prefix: list with filters, detail with occurrence timeline, resolve/mute/assign.

---

## Requirements

- PHP 8.2+ (8.4+ on Symfony 8)
- Symfony 6.4, 7.x or 8.x
- Doctrine ORM 2.14+ / 3, DBAL 3 / 4
- Monolog 3 + `symfony/monolog-bundle` 3 or 4
- Messenger + Mailer (host probably already has these)

Optional:

- `symfony/notifier` + a chat transport (Slack, Teams, Discord, Rocket.Chat) for chat pings
- `symfony/scheduler` for cron-less scheduling (or use OS cron + the console command)

---

## Install

### 1. Require the package

```bash
composer require hexis/error-digest-bundle
```

If running inside a monorepo with a path repository, add this to root `composer.json`:

```json
"repositories": [
  { "type": "path", "url": "packages/error-digest-bundle" }
],
"require": {
  "hexis/error-digest-bundle": "@dev"
}
```

### 2. Register the bundle

```php
// config/bundles.php
return [
    // ...
    Hexis\ErrorDigestBundle\ErrorDigestBundle::class => ['all' => true],
];
```

### 3. Configure Doctrine migrations

```yaml
# config/packages/doctrine_migrations.yaml
doctrine_migrations:
    migrations_paths:
        'DoctrineMigrations': '%kernel.project_dir%/migrations'
        'Hexis\ErrorDigestBundle\Migrations': '%kernel.project_dir%/vendor/hexis/error-digest-bundle/src/Resources/migrations'
```

*(path-repo installs symlink, so that path resolves via the vendor symlink)*

### 4. Configure routing

The bundle ships two route groups — the admin UI and the JS ingest endpoint — under separate controller subdirectories so you can host-constrain or auth-gate them independently:

```yaml
# config/routes/error_digest.yaml
error_digest_admin:
    resource: '@ErrorDigestBundle/src/Controller/Admin/'
    type: attribute
    prefix: /_errors
    # Optional: restrict to a host, e.g. for a superadmin subdomain
    # host: 'superadmin.{_domain}'
    # defaults: { _domain: '%app.base_domain%' }
    # requirements: { _domain: '.+' }

error_digest_ingest:
    resource: '@ErrorDigestBundle/src/Controller/Ingest/'
    type: attribute
    prefix: /_errors/ingest
    # Public POST endpoint for browser error reports — leave host-unconstrained
    # so any subdomain in your app can send to it.
```

> **Upgrading from v0.1.x?** The admin controllers moved from `Controller/` to `Controller/Admin/`. Update your `resource:` path accordingly. Route names (`error_digest_dashboard`, etc.) are unchanged.

### 5. Configure the bundle

```yaml
# config/packages/error_digest.yaml
error_digest:
    enabled: true
    minimum_level: warning              # debug | info | notice | warning | error | critical | alert | emergency
    channels: ~                         # null/empty = all channels
    environments: [prod, dev]
    storage:
        connection: default             # doctrine connection name
        table_prefix: err_
        occurrence_retention_days: 30
    ignore:
        - { class: Symfony\Component\HttpKernel\Exception\NotFoundHttpException }
        - { class: Symfony\Component\Security\Core\Exception\AccessDeniedException }
        - { channel: deprecation, level: notice }
    digest:
        enabled: true
        schedule: '0 8 * * *'           # informational; bundle doesn't schedule itself
        recipients: ['%env(ADMIN_EMAIL)%']
        from: 'noreply@%env(APP_DOMAIN)%'
        senders: [mailer]               # + 'notifier' to enable chat pings
        window: '24 hours'
        sections: [new, spiking, top, stale]
        top_limit: 10
        stale_days: 7
        spike_multiplier: 3.0           # window vs prior window ratio to count as "spiking"
        notifier_transports: []         # named Notifier transports, empty = default
    ui:
        enabled: true
        route_prefix: /_errors
        role: ROLE_ADMIN
    rate_limit:
        per_fingerprint_seconds: 1      # within this window, one occurrence row per fingerprint
    js:
        enabled: true
        allowed_origins: ['https://%env(APP_DOMAIN)%', 'https://*.%env(APP_DOMAIN)%']
        max_payload_bytes: 16384
        max_stack_lines: 50
        rate_limit_per_minute: 30       # per-IP cap
        client_max_per_page: 50         # browser-side cap per page-load
        client_dedup_window_ms: 5000    # browser-side dedup
        release: '%env(default::APP_RELEASE)%'  # optional, included in fingerprint context
```

### 6. Run migrations

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

Two tables are created: `err_fingerprint` (lifetime state per error signature) and `err_occurrence` (rolling event log).

---

## Trigger the digest

The bundle does not schedule itself. You wire the trigger via OS cron, a Symfony Scheduler recipe of your own, or whatever orchestrator you already use. Example cron:

```
0 8 * * *    docker compose exec -T php php bin/console error-digest:send-digest
```

### Console commands

| Command | Purpose |
|---|---|
| `error-digest:send-digest [--dry-run] [--as-of=Y-m-d H:i:s]` | Build the digest and dispatch to configured senders. |
| `error-digest:fingerprint-test [--count=N] [--message=…] [--level=…] [--ignored]` | Emit a synthetic exception so you can verify the capture pipeline end-to-end. |
| `error-digest:prune [--older-than=30d] [--dry-run]` | Delete occurrence rows older than the threshold. Fingerprint rows and lifetime counters are preserved. |
| `error-digest:list [--status=open] [--since=7d] [--limit=50]` | Print fingerprints as JSON for triage (by developers, scripts or an AI agent). Read-only. |

### Listing errors as JSON

`error-digest:list` prints the fingerprints matching the filters, most recently seen first, to stdout as JSON (errors go to stderr, exit code 2 on an invalid option):

- `--status`: `open` (default), `resolved`, `muted` or `all`
- `--since`: only fingerprints last seen since `7d`, `12h`, `90m`, `Y-m-d` or `"Y-m-d H:i:s"`
- `--limit`: maximum number of items (default 50); `total` still counts every match

```bash
bin/console error-digest:list --since=1d --limit=20 > open-errors.json
```

```json
{
    "generated_at": "2026-10-15T09:00:00+02:00",
    "filters": { "status": "open", "since": "2026-10-14T09:00:00+02:00", "limit": 20 },
    "total": 1,
    "items": [
        {
            "id": 3,
            "fingerprint": "d8412648e6c7b2d88468944bd582e88bdd9090f8efe6f8702ece99579bd75301",
            "status": "open",
            "level": "critical",
            "channel": "request",
            "environment": "prod",
            "exception_class": "LogicException",
            "message": "Partner 4711 could not be saved, Bearer [REDACTED] rejected",
            "file": "/app/src/Partner/PartnerService.php",
            "line": 63,
            "occurrence_count": 2,
            "first_seen_at": "2026-10-15T08:59:31+02:00",
            "last_seen_at": "2026-10-15T08:59:32+02:00",
            "latest_occurrence": {
                "occurred_at": "2026-10-15T08:59:32+02:00",
                "method": "POST",
                "request_uri": "/partners/4711",
                "release": null,
                "trace_preview": "LogicException: Partner 4711 could not be saved, Bearer [REDACTED] rejected\n  at /app/src/Partner/PartnerService.php:63\n  #0 ..."
            }
        }
    ]
}
```

`latest_occurrence` is the most recent occurrence row (`null` once pruned); `release` is set for browser errors. `message`, `trace_preview` and `request_uri` are stored scrubbed and pass through the configured `PiiScrubber` again (rows stored by older versions are not), query strings and fragments are cut from `request_uri`, and occurrence context and user references are left out. The export is scrubbed, not anonymized: the default scrubber masks only the patterns listed under [PII scrubbing](#pii-scrubbing), so names, phone numbers, postal addresses and other personal data in free text are printed as stored. Review an export before sharing it, or configure a stricter `PiiScrubber`.

---

## How capture works

### Handler registration

The bundle auto-registers its Monolog handler at boot via `prependExtension`, so you don't edit `monolog.yaml` manually. The Monolog, Doctrine and migrations config the bundle adds goes in front of yours, so your own config wins: `monolog.handlers.error_digest.id` can point at a service of yours (one that decorates `ErrorDigestHandler`, say), and `doctrine.orm.mappings.ErrorDigest` or `doctrine_migrations.migrations_paths` can replace the bundle's directories. The handler:

1. Filters by `minimum_level`, `channels`, `environments`, and `ignore` rules.
2. Fingerprints the record via `Hexis\ErrorDigestBundle\Domain\Fingerprinter`.
3. Buffers in memory, keyed by fingerprint, tracking count + first/last-seen timestamps.
4. Rate-limits occurrence-row writes: one write per fingerprint per `per_fingerprint_seconds` window.
5. Flushes to the database on `kernel.terminate` and `console.terminate` via a single DBAL transaction.

### Fingerprinting

The default fingerprinter hashes a stable tuple:

- With exception: `class | file | line | normalized_message`
- Without exception: `"log" | channel | level | normalized_message`

Message normalization strips UUIDs → `UUID`, long hex hashes → `HASH`, bare integers → `0` — so "user 42 not found" and "user 4711 not found" fingerprint identically.

To plug your own, implement `Hexis\ErrorDigestBundle\Domain\Fingerprinter` and wire it:

```yaml
error_digest:
    dedup:
        fingerprinter: my.custom.fingerprinter.service.id
```

### PII scrubbing

Everything the bundle stores passes the configured scrubber first: the message, the trace preview, the request URI and the context of every occurrence (`_extra` included), and for browser errors the message, type, source and page URL, stack and user reference. URLs (the request URI, the referrer, the page and source URL of a browser error) lose their query string and fragment before that, and objects in the context are stored by class name only, whatever the scrubber returns. A text the scrubber drops or turns into a non-string is stored as `[REDACTED]`.

`DefaultPiiScrubber`, the scrubber unless `error_digest.scrubber` names another, redacts:

- Keys matching (case-insensitive, substring): `password`, `token`, `authorization`, `cookie`, `session`, `csrf`, `jwt`, `api_key`, `secret`, etc.
- Credit-card-shaped number sequences anywhere in values, and integers of 13 to 19 digits.
- Bearer tokens (`Bearer xxx` → `Bearer [REDACTED]`).
- JWT-shaped strings (three base64url segments joined by `.`).
- E-mail addresses.
- Query strings and fragments of URLs (`https://example.com/reset?[REDACTED]`) and credentials in URLs (`mysql://[REDACTED]@db/app`).

Objects are kept by class name only (`[object App\Entity\Partner]`), never with their properties. If a pattern fails (the PCRE backtrack limit, say), the whole value is replaced with `[REDACTED]`: the scrubber fails closed.

Override by implementing `Hexis\ErrorDigestBundle\Domain\PiiScrubber` and setting `error_digest.scrubber` to your service id.

### Safety properties

- **Handler is non-blocking on error.** Anything thrown during capture or flush is caught and sent to `error_log()` — never re-entered into Monolog, so no capture storm.
- **Direct DBAL writes**, not ORM. Handler's transaction is independent of the host's EntityManager; logging inside a rollback is safe.
- **Handler stays lazy.** Buffer lives only for the request/command lifetime. No background state.

---

## UI

Once wired, the dashboard is at `{route_prefix}/` (default `/_errors/`). Requires the role configured in `error_digest.ui.role` (default `ROLE_ADMIN`).

| Page | URL | What you can do |
|---|---|---|
| Dashboard | `GET /_errors/` | Filter by status / level / channel, search in message & class & fingerprint, paginate |
| Detail | `GET /_errors/fingerprint/{id}` | Inspect message, stack trace, occurrence timeline, scrubbed context per occurrence |
| Status | `POST /_errors/fingerprint/{id}/status` | Mark resolved / muted / re-open |
| Assign | `POST /_errors/fingerprint/{id}/assign` | Set an assignee (free-form string ref — email or user id) |

A resolved fingerprint that occurs again (server or browser) is open again, with its assignee kept; a muted one stays muted.

CSRF tokens are required on both POST endpoints and are baked into the templates via `csrf_token()`.

The templates use Bootstrap 5 via CDN by default. Override with your own by placing files in `templates/bundles/ErrorDigestBundle/`.

---

## Digest sections

| Section | Definition |
|---|---|
| **new** | Fingerprints first seen within `digest.window` AND with `notified_in_digest_at IS NULL`. Strongest signal for "something just broke." |
| **spiking** | Window occurrence count > `spike_multiplier` × max(1, prior-window occurrence count). |
| **top** | Open fingerprints with the most occurrences in the window. Limited by `digest.top_limit`. |
| **stale** | Status=open, first seen more than `stale_days` ago. Reminders that unresolved issues are still alive. |

After each successful send, fingerprints included in the digest get `notified_in_digest_at` stamped so "new" stays new.

---

## Messenger integration

`SendDailyDigest` is dispatched via the default message bus when you run `error-digest:send-digest`. To push actual digest building off the web request path, route it to an async transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'Hexis\ErrorDigestBundle\Message\SendDailyDigest': async
```

The digest handler never runs in the request path by itself — it's only dispatched by the console command or your scheduler.

---

## Browser error capture (since v0.2.0)

JS errors that fire in users' browsers go undetected by default — Monolog only sees server-side. The bundle ships a vanilla-JS client + ingest endpoint so browser errors land in the same `err_fingerprint` table, channel = `js`, alongside server-side captures.

### Wire it in

The ingest controller registers a public POST route under `Controller/Ingest/`. Once your routing splits `Admin/` from `Ingest/` (see install step 4), the endpoint is live at the prefix you configured.

Then publish the JS file:

```bash
bin/console assets:install
```

This copies `error-digest.js` to `public/bundles/errordigest/error-digest.js`. Include it in your base layout via the Twig helper:

```twig
<!-- in base.html.twig, before </body> -->
{{ error_digest_script() }}

<!-- with optional release + user id baked in -->
{{ error_digest_script({release: app_version, user: app.user.id}) }}
```

The helper emits a `<script defer>` tag pointing at the bundled JS file with `data-endpoint` set to the ingest route URL (so URL generation handles your subdomain config automatically).

### What gets captured

- `window.error` events — synchronous JS errors + resource load failures
- `unhandledrejection` events — async / Promise rejections
- Anything you forward manually via `window.errorDigest.capture(error, {extra})`

Each report carries: message, exception type, source URL, line, column, stack trace, page URL, user agent, optional release + user.

### Safety properties

- **Client-side dedup** — same fingerprint within `client_dedup_window_ms` is sent once
- **Page-load cap** — at most `client_max_per_page` reports per page (kills runaway loops)
- **Server-side per-IP rate limit** — `rate_limit_per_minute` cap, configurable, 0 disables
- **Origin allowlist** — `allowed_origins` controls which sites' browsers can POST. Supports wildcards (`https://*.example.com`).
- **Payload caps** — request body capped at `max_payload_bytes`, stack trimmed to `max_stack_lines`
- **Always 204** — endpoint never leaks validation/policy info to clients
- **`navigator.sendBeacon`** — flushes on `pagehide` so errors right before navigation aren't lost

### Manual API

```html
<script>
    try {
        riskyOperation();
    } catch (e) {
        window.errorDigest.capture(e, {source: 'checkout-form'});
    }
</script>
```

### What's deliberately NOT captured

- `console.error` calls — too noisy (third-party scripts, devtools warnings)
- Network errors / failed fetches — separate concern; use the manual API if you need it
- Source-map resolution — line/col stays as minified positions; resolve from devtools

---

## Multi-tenant / separate-connection setups

If your default connection is tenant-scoped (e.g., via a DBAL wrapper that filters by tenant id), you probably want errors to flow into a single cross-tenant connection. Two settings control the routing:

```yaml
error_digest:
    storage:
        connection: superadmin       # DBAL writes go through this connection
        entity_manager: superadmin   # Doctrine mapping attaches to this EM
```

- **`connection`** — which DBAL connection the handler and digest builder use for reads/writes. Defaults to `default`.
- **`entity_manager`** (since v0.1.1) — which EntityManager gets the `ErrorDigest` mapping. Defaults to `null` which lands on the default EM. Set this to the EM that owns your non-default connection so `doctrine:schema:update --em=<name>` and `doctrine:migrations:migrate --em=<name>` operate on the right schema.

If another EntityManager has `auto_mapping: true`, Doctrine maps the bundle there as well. Exclude it on that EM with `mappings: { ErrorDigestBundle: false }`.

For a typical `default` (tenant) + `superadmin` (global) two-EM setup, point both settings at `superadmin`. Then run the bundle's migration against that EM once:

```bash
php bin/console doctrine:migrations:migrate --em=superadmin --no-interaction
```

Add the same invocation to your deploy pipeline alongside your regular `doctrine:migrations:migrate` (which runs on the default EM).

---

## Development

Bundle ships with a phpunit suite covering fingerprinter, scrubber, rate-limit buffer, handler filtering, DBAL writer (SQLite in-memory), and digest builder (SQLite in-memory):

```bash
cd packages/error-digest-bundle
../../vendor/bin/phpunit
```

---

## Roadmap

- LiveComponent-based live-tail view
- Fuzzy stack-trace clustering beyond fingerprint
- Threshold-based paging / alert rules engine
- Auto-issue creation (GitHub / Linear)
- Multi-app aggregator (N apps report into one central instance over HTTP)

---

## License

Proprietary — Hexis.
