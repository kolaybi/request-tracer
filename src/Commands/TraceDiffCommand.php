<?php

namespace KolayBi\RequestTracer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use KolayBi\RequestTracer\Commands\Concerns\BuildsEndpoint;
use KolayBi\RequestTracer\Commands\Concerns\QueriesArchiveTables;
use KolayBi\RequestTracer\Models\IncomingRequestTrace;
use KolayBi\RequestTracer\Models\OutgoingRequestTrace;

class TraceDiffCommand extends Command
{
    use BuildsEndpoint;
    use QueriesArchiveTables;

    protected $signature = 'request-tracer:diff {id1} {id2}';

    protected $description = 'Compare two traces side-by-side';

    public function handle(): int
    {
        $result1 = $this->resolveTrace($this->argument('id1'));
        $result2 = $this->resolveTrace($this->argument('id2'));

        if (!$result1 || !$result2) {
            return self::FAILURE;
        }

        $this->renderMetadataDiff($result1, $result2);

        if ($this->output->isVerbose()) {
            $this->renderHeaderDiff($result1, $result2);
        }

        if ($this->output->isVeryVerbose()) {
            $this->renderBodyDiff($result1, $result2);
        }

        return self::SUCCESS;
    }

    private function resolveTrace(string $id): ?array
    {
        $outgoingModel = config('kolaybi.request-tracer.outgoing.model', OutgoingRequestTrace::class);

        /** @var OutgoingRequestTrace|null $trace */
        $trace = $this->findAcrossTables($outgoingModel, $id);

        if ($trace) {
            return ['trace' => $trace, 'type' => 'OUTGOING'];
        }

        $incomingModel = config('kolaybi.request-tracer.incoming.model', IncomingRequestTrace::class);

        /** @var IncomingRequestTrace|null $trace */
        $trace = $this->findAcrossTables($incomingModel, $id);

        if ($trace) {
            return ['trace' => $trace, 'type' => 'INCOMING'];
        }

        $this->error("Trace not found: {$id}");

        return null;
    }

    private function renderMetadataDiff(array $r1, array $r2): void
    {
        $t1 = $r1['trace'];
        $t2 = $r2['trace'];

        $this->components->twoColumnDetail('<fg=gray>Trace 1</>', "{$t1->id} [{$r1['type']}]");
        $this->components->twoColumnDetail('<fg=gray>Trace 2</>', "{$t2->id} [{$r2['type']}]");
        $this->newLine();

        $fields = [
            'Method'        => [$t1->method, $t2->method],
            'Endpoint'      => [$this->buildEndpoint($t1, $r1['type']), $this->buildEndpoint($t2, $r2['type'])],
            'Status'        => [$t1->status, $t2->status],
            'Duration'      => [
                null !== $t1->duration ? "{$t1->duration}ms" : '—',
                null !== $t2->duration ? "{$t2->duration}ms" : '—',
            ],
            'Request Size'  => [$t1->request_size ?? '—', $t2->request_size ?? '—'],
            'Response Size' => [$t1->response_size ?? '—', $t2->response_size ?? '—'],
        ];

        foreach ($fields as $label => [$v1, $v2]) {
            $this->renderDiffRow($label, $v1, $v2);
        }

        $this->newLine();
    }

    private function renderDiffRow(string $label, mixed $v1, mixed $v2): void
    {
        $s1 = (string) ($v1 ?? '—');
        $s2 = (string) ($v2 ?? '—');

        if ($s1 === $s2) {
            $this->line(sprintf('  %-20s %s', $label, $s1));
        } else {
            $this->line(sprintf('  %-20s <fg=red>%s</> → <fg=green>%s</>', $label, $s1, $s2));
        }
    }

    private function renderHeaderDiff(array $r1, array $r2): void
    {
        $this->components->twoColumnDetail('<fg=yellow>Request Headers</>');
        $this->renderHeaderDiffOutput(
            $this->diffHeaders(
                $this->parseHeaders($r1['trace']->request_headers),
                $this->parseHeaders($r2['trace']->request_headers),
            ),
        );

        $this->newLine();

        $this->components->twoColumnDetail('<fg=yellow>Response Headers</>');
        $this->renderHeaderDiffOutput(
            $this->diffHeaders(
                $this->parseHeaders($r1['trace']->response_headers),
                $this->parseHeaders($r2['trace']->response_headers),
            ),
        );

        $this->newLine();
    }

    private function parseHeaders(?string $raw): array
    {
        if (null === $raw || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
            return array_map(
                fn($v) => is_array($v) ? implode(', ', $v) : (string) $v,
                $decoded,
            );
        }

        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);

            if (2 === count($parts)) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }

    private function diffHeaders(array $a, array $b): array
    {
        $a = array_change_key_case($a, CASE_LOWER);
        $b = array_change_key_case($b, CASE_LOWER);
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
        sort($allKeys);

        $diff = [];

        foreach ($allKeys as $key) {
            $inA = array_key_exists($key, $a);
            $inB = array_key_exists($key, $b);

            if ($inA && !$inB) {
                $diff[] = ['key' => $key, 'status' => 'removed', 'val_a' => $a[$key], 'val_b' => null];
            } elseif (!$inA && $inB) {
                $diff[] = ['key' => $key, 'status' => 'added', 'val_a' => null, 'val_b' => $b[$key]];
            } elseif ($a[$key] !== $b[$key]) {
                $diff[] = ['key' => $key, 'status' => 'changed', 'val_a' => $a[$key], 'val_b' => $b[$key]];
            }
        }

        return $diff;
    }

    private function renderHeaderDiffOutput(array $diff): void
    {
        if ([] === $diff) {
            $this->line('  <fg=gray>(no differences)</>');

            return;
        }

        foreach ($diff as $d) {
            match ($d['status']) {
                'removed' => $this->line("  <fg=red>- {$d['key']}: {$d['val_a']}</>"),
                'added'   => $this->line("  <fg=green>+ {$d['key']}: {$d['val_b']}</>"),
                'changed' => $this->line("  <fg=yellow>~ {$d['key']}: {$d['val_a']}</> → <fg=green>{$d['val_b']}</>"),
            };
        }
    }

    private function renderBodyDiff(array $r1, array $r2): void
    {
        $this->renderBodyPair('Request Body', $r1['trace']->request_body, $r2['trace']->request_body);
        $this->renderBodyPair('Response Body', $r1['trace']->response_body, $r2['trace']->response_body);
    }

    private function renderBodyPair(string $label, ?string $body1, ?string $body2): void
    {
        $this->components->twoColumnDetail("<fg=yellow>{$label}</>");

        $b1 = $body1 ?? '';
        $b2 = $body2 ?? '';

        if ($b1 === $b2) {
            $this->line('  <fg=gray>(identical)</>');
            $this->newLine();

            return;
        }

        similar_text($b1, $b2, $percent);
        $this->line(sprintf('  Similarity: %.1f%%', $percent));

        $this->line('  <fg=gray>[1]</> ' . Str::limit($b1, 200, '...'));
        $this->line('  <fg=gray>[2]</> ' . Str::limit($b2, 200, '...'));
        $this->newLine();
    }
}
