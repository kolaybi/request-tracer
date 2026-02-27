# Changelog

All notable changes to this project will be documented in this file.

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
