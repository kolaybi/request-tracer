<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\Soap\ConnectionFailedListener;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'soap-trace-id');
});

it('records soap connection failure trace', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');

    $exception = new RuntimeException('SOAP connection failed', 500);

    $event = new ConnectionFailedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><GetUser/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/GetUser',
        exception: $exception,
        channel: 'e-invoice',
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:05.000000',
    );

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'soap' === $job->attributes['protocol']
            && 'POST' === $job->attributes['method']
            && 500 === $job->attributes['status']
            && 'SOAP connection failed' === $job->attributes['message']
            && str_contains($job->attributes['exception'], 'ConnectionFailedListenerTest.php')
            && 'GetUser' === $job->attributes['query']
            && 'e-invoice' === $job->attributes['channel'];
    });
});

it('records soap failure with channel and extra', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');

    $exception = new RuntimeException('Service unavailable', 503);

    $event = new ConnectionFailedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><ProcessInvoice/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/ProcessInvoice',
        exception: $exception,
        channel: 'billing',
        extra: ['invoice_id' => 42],
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:03.000000',
    );

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'billing' === $job->attributes['channel']
            && '{"invoice_id":42}' === $job->attributes['extra']
            && 503 === $job->attributes['status'];
    });
});

it('records soap failure with string extra passed as-is', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');

    $exception = new RuntimeException('fail', 500);

    $event = new ConnectionFailedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><Action/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/Action',
        exception: $exception,
        channel: null,
        extra: 'raw-string-data',
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:01.000000',
    );

    $listener = new ConnectionFailedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'raw-string-data' === $job->attributes['extra'];
    });
});
