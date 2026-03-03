<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;
use Symfony\Component\Console\Output\OutputInterface;

class TraceInspectCommand extends Command
{
    use BuildsEndpoint;

    protected $signature = 'request-tracer:inspect {id} {--full}';

    protected $description = 'Inspect a single trace record by its ULID';

    public function handle(): int
    {
        $id = $this->argument('id');

        $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);
        $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);

        $trace = $outgoingModel::find($id);
        $type = 'OUTGOING';

        if (!$trace) {
            $trace = $incomingModel::find($id);
            $type = 'INCOMING';
        }

        if (!$trace) {
            $this->error("Trace not found: {$id}");

            return self::FAILURE;
        }

        $this->renderMetadata($trace, $type);

        if ($this->verbosity() >= 1) {
            $this->renderHeaders($trace);
        }

        if ($this->verbosity() >= 2) {
            $this->renderBodies($trace, $type);
        }

        return self::SUCCESS;
    }

    private function renderMetadata(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): void
    {
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        $this->components->twoColumnDetail('<fg=gray>Type</>', $type);
        $this->components->twoColumnDetail('<fg=gray>Method</>', $trace->method ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Endpoint</>', $this->buildEndpoint($trace, $type));
        $this->components->twoColumnDetail('<fg=gray>Status</>', $this->colorStatus($trace->status));
        $this->components->twoColumnDetail('<fg=gray>Duration</>', null !== $trace->duration ? "{$trace->duration}ms" : '—');
        $this->components->twoColumnDetail('<fg=gray>Start</>', $trace->start ?? '—');
        $this->components->twoColumnDetail('<fg=gray>End</>', $trace->end ?? '—');

        if ('OUTGOING' === $type) {
            $this->components->twoColumnDetail('<fg=gray>Channel</>', $trace->channel ?? '—');
            $this->components->twoColumnDetail('<fg=gray>Protocol</>', $trace->protocol ?? '—');
        }

        $this->components->twoColumnDetail('<fg=gray>Server</>', $trace->server_identifier ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Tenant ID</>', (string) ($trace->{$tenantColumn} ?? '—'));
        $this->components->twoColumnDetail('<fg=gray>User ID</>', (string) ($trace->user_id ?? '—'));
        $this->components->twoColumnDetail('<fg=gray>Client IP</>', $trace->client_ip ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Trace ID</>', $trace->trace_id ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Request Size</>', $this->formatSize($trace->request_size));
        $this->components->twoColumnDetail('<fg=gray>Response Size</>', $this->formatSize($trace->response_size));
    }

    private function renderHeaders(IncomingRequestTrace|OutgoingRequestTrace $trace): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Request Headers</>');
        $this->renderHeaderBlock($trace->request_headers);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Response Headers</>');
        $this->renderHeaderBlock($trace->response_headers);
    }

    private function renderHeaderBlock(?string $raw): void
    {
        if (!$raw) {
            $this->line('  —');

            return;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $display = is_array($value) ? implode(', ', $value) : (string) $value;
                $this->line("  <fg=gray>{$key}:</> {$display}");
            }
        } else {
            $this->line("  {$raw}");
        }
    }

    private function renderBodies(IncomingRequestTrace|OutgoingRequestTrace $trace, string $type): void
    {
        $full = $this->option('full');
        $maxLines = match (true) {
            $full                   => null,
            $this->verbosity() >= 3 => 40,
            default                 => 20,
        };

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Request Body</>');
        $this->renderBody($trace->request_body, $maxLines);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Response Body</>');
        $this->renderBody($trace->response_body, $maxLines);

        if ($this->verbosity() >= 3 && 'OUTGOING' === $type) {
            $this->renderOutgoingExtras($trace);
        }
    }

    private function renderBody(?string $body, ?int $maxLines): void
    {
        if (!$body) {
            $this->line('  —');

            return;
        }

        $decoded = json_decode($body, true);
        $text = null !== $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;

        $lines = explode("\n", $text);

        if (null !== $maxLines && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = '<fg=gray>[truncated, use --full to see all]</>';
        }

        foreach ($lines as $line) {
            $this->line("  {$line}");
        }
    }

    private function renderOutgoingExtras(OutgoingRequestTrace $trace): void
    {
        if ($trace->exception) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Exception</>');
            $this->line("  {$trace->exception}");
        }

        if ($trace->message) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Message</>');
            $this->line("  {$trace->message}");
        }

        if ($trace->stats) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Stats</>');
            $this->renderBody($trace->stats, null);
        }

        if ($trace->extra) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Extra</>');
            $this->renderBody($trace->extra, null);
        }
    }

    private function colorStatus(?int $status): string
    {
        if (null === $status) {
            return '—';
        }

        $color = match (true) {
            $status >= 500 => 'red',
            $status >= 400 => 'yellow',
            $status >= 300 => 'cyan',
            $status >= 200 => 'green',
            default        => 'white',
        };

        return "<fg={$color}>{$status}</>";
    }

    private function formatSize(?int $size): string
    {
        if (null === $size) {
            return '—';
        }

        return number_format($size) . ' bytes';
    }

    private function verbosity(): int
    {
        if ($this->option('full')) {
            return 3;
        }

        return match ($this->output->getVerbosity()) {
            OutputInterface::VERBOSITY_VERBOSE      => 1,
            OutputInterface::VERBOSITY_VERY_VERBOSE => 2,
            OutputInterface::VERBOSITY_DEBUG        => 3,
            default                                 => 0,
        };
    }
}
