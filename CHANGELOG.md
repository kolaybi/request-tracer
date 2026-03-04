# Changelog

All notable changes to this project will be documented in this file.

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
- Auto-load package migrations

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

This is the initial release of the this package
