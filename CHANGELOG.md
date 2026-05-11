# Changelog

All notable changes to this project will be documented in this file.

## [v1.5.0](https://github.com/kolaybi/request-tracer/compare/v1.4.0...v1.5.0) (2026-05-11)

### Added
- `request-tracer:preserve` command — sweeps matching rows from dated archive tables into permanent `*_persistent` tables, surviving rotation and `retention_days`. Designed to run after `request-tracer:rotate` via `->then()`. Supports `--date=YYYYMMDD` for single-archive runs, `--all` for backfills, and `--direction=incoming|outgoing|both`.
- `REQUEST_TRACER_INCOMING_PERSIST` env / `incoming.persist` config — comma-separated path glob patterns to preserve (same semantics as `incoming.only`).
- `REQUEST_TRACER_OUTGOING_PERSIST` env / `outgoing.persist` config — comma-separated `host+path` glob patterns to preserve (same semantics as `outgoing.only`).
- `incoming_request_traces_persistent` and `outgoing_request_traces_persistent` migrations.
- `TraceTableBlueprint` helper — shared column definitions used by all trace-table migrations.

### Changed
- Existing trace-table migrations now use `TraceTableBlueprint` (schema unchanged).
- Removed unused `$direction` parameter from `TraceTailCommand::applyFilters` and `TraceSearchCommand::buildQuery` — both methods only filter on `host`/`status`/`channel`/etc., never on direction.
- Migrated test mocks from legacy `Mockery::shouldReceive()` to Mockery 1.5+ `allows()`/`expects()`. Facade `DB::shouldReceive(...)` calls remain — that is the Facade entry point.

## [v1.4.0](https://github.com/kolaybi/request-tracer/compare/v1.3.0...v1.4.0) (2026-03-10)

### Added
- `channel` support for incoming traces — resolved from a configurable request header (`incoming.channel_header`) or middleware parameter, with header taking priority

## [v1.3.0](https://github.com/kolaybi/request-tracer/compare/v1.2.0...v1.3.0) (2026-03-10)

### Added
- `QueriesArchiveTables` trait for querying across current and archive tables
- `request-tracer:diff {id1} {id2}` command — side-by-side trace comparison with metadata diff, header diff (`-v`), and body diff with similarity percentage (`-vv`)
- `request-tracer:rotate` command — daily table rotation with dated archive tables and automatic cleanup of old archives
- `request-tracer:search` command — filter traces by host, path, status (exact or range like `5xx`), method, channel, date range, duration, and type
- `request-tracer:stats` command — aggregate statistics with `--hours` and `--type` filtering, top hosts, top channels, duration stats, and error rate
- `request-tracer:tail` command — live-tail new traces as they arrive with ULID-based cursor tracking, color-coded status, and `--type`, `--host`, `--status`, `--channel`, `--interval` filters
- `exclude_body_content_types` config — skip body capture for binary content types (e.g. `image/`, `video/`, `application/pdf`)
- Query string masking — sensitive keys in query params (e.g. `access_token`, `api_key`) are now masked when `mask_sensitive` is enabled

### Changed
- Renamed `REQUEST_TRACER_SAMPLE_RATE` env variable to `REQUEST_TRACER_OUTGOING_SAMPLE_RATE` for consistency with `REQUEST_TRACER_INCOMING_SAMPLE_RATE`
- Removed unnecessary `SerializesModels` trait from `StoreTraceJob` (only carries scalar/array properties)
- Sensitive key resolution and mask value are computed once per normalize call instead of per-key for better performance
- Added `created_at` datetime cast to both trace models (supplements existing integer casts from v1.1.0)

### Fixed
- `extractSoapAction` return type changed to `?string` — prevents `TypeError` when SOAP action is empty and XML body parsing fails
- `TraceWaterfallCommand` total duration display could go negative due to clock drift — now clamped to zero
- `maskHeaders` now detects original line endings (`\r\n` vs `\n`) instead of using `PHP_EOL`, preserving header format on all platforms

## [v1.2.0](https://github.com/kolaybi/request-tracer/compare/v1.1.1...v1.2.0) (2026-03-09)

### Added
- Sampling rate for incoming traces (`incoming.sample_rate`)
- Route filtering for incoming traces (`incoming.only` / `incoming.except`) with wildcard support, env-configurable
- URL filtering for outgoing traces (`outgoing.only` / `outgoing.except`) with wildcard support, env-configurable

## [v1.1.1](https://github.com/kolaybi/request-tracer/compare/v1.1.0...v1.1.1)  (2026-03-04)

### Added
- Add `.gitattributes` to enforce consistent Git attributes across platforms

## [v1.1.0](https://github.com/kolaybi/request-tracer/compare/v1.0.2...v1.1.0)  (2026-03-03)

### Added
- Eloquent casts for integer columns on both trace models (`duration`, `status`, `request_size`, `response_size`, `user_id`, tenant column)
- `tenant_cast` and `user_cast` config options — set to `'string'` for ULID/UUID keys
- Added Test suite

### Changed
- Consolidate shared SOAP and endpoint logic into base classes

### Fixed
- Negative duration from clock drift no longer breaks inserts
- SOAP one-way calls store `response_size` as `null` instead of `0`
- Sensitive keys config is read fresh on each request instead of cached statically
- Empty incoming trace collection no longer causes merge error
- Purge command uses correct date type for retention cutoff

## [v1.0.2](https://github.com/kolaybi/request-tracer/compare/v1.0.1...v1.0.2)  (2026-03-02)

### Added
- Autoload package migrations

### Changed
- Publish config to `config/kolaybi/request-tracer.php`

## [v1.0.1](https://github.com/kolaybi/request-tracer/compare/v1.0.0...v1.0.1)  (2026-03-02)

### Added
- Opt-in sensitive masking for headers and payloads via `mask_sensitive`, `mask_value`, and `sensitive_keys` config options

### Changed
- Store outgoing HTTP trace metadata on request attributes instead of `X-Trace-*` headers
- Normalize SOAP response headers and bodies before persisting traces

### Fixed
- Respect max body size truncation for very small limits
- Validate `--chunk` for `request-tracer:purge` to avoid infinite loops
- Safely capture incoming response size for streamed or binary responses

## [v1.0.0](https://github.com/kolaybi/request-tracer/commits/v1.0.0)  (Unreleased)

### Added
- Outgoing HTTP request tracing via Laravel's `Http` client events
- Outgoing SOAP request tracing via `TracingSoapClient`
- Incoming request tracing via `RequestTracerMiddleware`
- `Http::channel()` / `Http::traceOf()` / `Http::withTraceExtra()` mixin methods
- Per-request metadata via HTTP headers (`X-Trace-Channel`, `X-Trace-Extra`, `X-Trace-Started-At`, `X-Trace-Finished-At`) — no shared state, safe with `Http::pool()`
- `TraceContextProvider` contract for app-specific tenant, user, and server info
- Configurable tenant column name (`tenant_id`, `company_id`, etc.)
- Separate database tables for outgoing and incoming traces
- `duration` column (milliseconds) computed in application layer for database engine portability
- `trace_id` via Laravel `Context` — links incoming and outgoing traces, auto-resets between queue jobs
- Lazy `trace_id` generation for worker/job environments
- `StoreTraceJob` for async trace persistence
- `PurgeTracesCommand` with chunked deletes and configurable retention
- `TraceWaterfallCommand` (`request-tracer:waterfall {trace_id}`) — displays a chronological waterfall table of all incoming and outgoing traces for a given trace ID
- `TraceInspectCommand` (`request-tracer:inspect {id} {--full}`) — drill into a single trace with progressive verbosity (`-v` headers, `-vv` body, `-vvv` extras, `--full` no truncation)
- ULID primary keys for globally unique trace IDs across tables
- Sampling rate for outgoing traces
- Body truncation with configurable max size
- UTF-8 body sanitization (binary bodies are base64-encoded)
- Independent `outgoing.enabled` / `incoming.enabled` toggles
- Auto-discovery via Laravel package service provider

## v0.0.0 (Unreleased)
- initial

## Notes

This is the initial release of this package
