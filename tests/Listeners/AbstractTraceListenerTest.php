<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\AbstractTraceListener;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'test-trace-id');

    $this->listener = new class () extends AbstractTraceListener {
        public function callBuildTraceAttributes(...$args): array
        {
            return $this->buildTraceAttributes(...$args);
        }

        public function callExtractTraceString(mixed $value): ?string
        {
            return $this->extractTraceString($value);
        }

        public function callExtractSoapBodyOperationName(string $request): ?string
        {
            return $this->extractSoapBodyOperationName($request);
        }

        public function callPersistTrace(array $attributes): void
        {
            $this->persistTrace($attributes);
        }
    };
});

it('extractTraceString returns last element of array', function () {
    expect($this->listener->callExtractTraceString(['first', 'last']))->toBe('last');
});

it('extractTraceString returns null for empty string', function () {
    expect($this->listener->callExtractTraceString(''))->toBeNull();
});

it('extractTraceString returns null for null', function () {
    expect($this->listener->callExtractTraceString(null))->toBeNull();
});

it('extractTraceString casts non-string to string', function () {
    expect($this->listener->callExtractTraceString(42))->toBe('42');
});

it('extractSoapBodyOperationName returns null for invalid XML', function () {
    expect($this->listener->callExtractSoapBodyOperationName('not xml'))->toBeNull();
});

it('extractSoapBodyOperationName extracts operation from valid SOAP envelope', function () {
    $xml = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><GetInvoice/></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    expect($this->listener->callExtractSoapBodyOperationName($xml))->toBe('GetInvoice');
});

it('persistTrace respects sample rate 1.0 (always trace)', function () {
    config(['kolaybi.request-tracer.outgoing.sample_rate' => 1.0]);

    $this->listener->callPersistTrace(['host' => 'example.com', 'start' => null, 'end' => null]);

    Queue::assertPushed(StoreTraceJob::class);
});

it('persistTrace drops trace at sample rate 0.0', function () {
    config(['kolaybi.request-tracer.outgoing.sample_rate' => 0.0]);

    $this->listener->callPersistTrace(['host' => 'example.com', 'start' => null, 'end' => null]);

    Queue::assertNotPushed(StoreTraceJob::class);
});
