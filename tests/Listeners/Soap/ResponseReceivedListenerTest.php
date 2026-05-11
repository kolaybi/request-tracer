<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent;
use KolayBi\RequestTracer\Jobs\StoreTraceJob;
use KolayBi\RequestTracer\Listeners\Soap\ResponseReceivedListener;

beforeEach(function () {
    Queue::fake();
    Context::add('trace_id', 'soap-trace-id');
});

it('records soap response trace', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('Content-Type: text/xml');
    $soapClient->allows('__getLastResponseHeaders')->andReturn("HTTP/1.1 200 OK\r\nContent-Type: text/xml");

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><GetUser/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/GetUser',
        response: '<soap:Envelope><soap:Body><GetUserResponse/></soap:Body></soap:Envelope>',
        channel: 'e-invoice',
        extra: ['invoice_id' => 123],
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:01.000000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return 'soap' === $job->attributes['protocol']
            && 'POST' === $job->attributes['method']
            && 'soap.example.com' === $job->attributes['host']
            && 'GetUser' === $job->attributes['query']
            && 200 === $job->attributes['status']
            && 'e-invoice' === $job->attributes['channel']
            && 'soap-trace-id' === $job->attributes['trace_id'];
    });
});

it('extracts status code from response headers', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');
    $soapClient->allows('__getLastResponseHeaders')->andReturn("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/xml");

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><Fail/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/Fail',
        response: '<error/>',
        channel: null,
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:00.500000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 500 === $job->attributes['status']);
});

it('returns 0 status when headers have no status code', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');
    $soapClient->allows('__getLastResponseHeaders')->andReturn('');

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: '<request/>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/Test',
        response: null,
        channel: null,
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:00.100000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 0 === $job->attributes['status']);
});

it('extracts action from soap body when tempuri is empty', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');
    $soapClient->allows('__getLastResponseHeaders')->andReturn("HTTP/1.1 200 OK\r\n");

    $soapBody = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><CustomOperation/></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: $soapBody,
        location: 'https://soap.example.com/service',
        action: '',
        response: '<response/>',
        channel: null,
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:00.100000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, fn(StoreTraceJob $job) => 'CustomOperation' === $job->attributes['query']);
});

it('stores null response_size when response is null', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('');
    $soapClient->allows('__getLastResponseHeaders')->andReturn('');

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: '<request/>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/OneWay',
        response: null,
        channel: null,
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:00.100000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) {
        return null === $job->attributes['response_size']
            && null === $job->attributes['response_body'];
    });
});

it('captures response size correctly', function () {
    $soapClient = Mockery::mock(SoapClient::class);
    $soapClient->allows('__getLastRequestHeaders')->andReturn('Content-Type: text/xml');
    $soapClient->allows('__getLastResponseHeaders')->andReturn("HTTP/1.1 200 OK\r\nContent-Type: text/xml");

    $responseBody = '<soap:Envelope><soap:Body><Result>Data here</Result></soap:Body></soap:Envelope>';

    $event = new ResponseReceivedEvent(
        soapClient: $soapClient,
        request: '<soap:Envelope><soap:Body><GetData/></soap:Body></soap:Envelope>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/GetData',
        response: $responseBody,
        channel: null,
        extra: null,
        start: '2026-01-01 00:00:00.000000',
        end: '2026-01-01 00:00:00.200000',
    );

    $listener = new ResponseReceivedListener();
    $listener->handle($event);

    Queue::assertPushed(StoreTraceJob::class, function (StoreTraceJob $job) use ($responseBody) {
        return strlen($responseBody) === $job->attributes['response_size'];
    });
});
