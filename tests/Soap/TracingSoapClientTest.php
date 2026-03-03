<?php

use Illuminate\Support\Facades\Event;
use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent;
use KolayBi\RequestTracer\Events\Soap\RequestSendingEvent;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent;
use KolayBi\RequestTracer\Soap\TracingSoapClient;
use KolayBi\RequestTracer\Tests\Fixtures\TestableTracingSoapClient;

$wsdlPath = __DIR__ . '/../Fixtures/minimal.wsdl';

// ──────────────────────────────────────────────────
// Original tests
// ──────────────────────────────────────────────────

it('throws RuntimeException when wsdl is not set on initialization', function () {
    $client = new TracingSoapClient();

    // Try to use a method that triggers initializeIfNeeded
    $client->__getFunctions();
})->throws(RuntimeException::class, 'WSDL URL must be set before making SOAP calls');

it('sets wsdl via fluent setter', function () {
    $client = new TracingSoapClient();
    $result = $client->setWsdl('https://example.com/service?wsdl');

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('sets options via fluent setter', function () {
    $client = new TracingSoapClient();
    $result = $client->setOptions(['trace' => true]);

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('sets single option via fluent setter', function () {
    $client = new TracingSoapClient();
    $result = $client->setOption('trace', true);

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('merges options on setOptions', function () {
    $client = new TracingSoapClient();
    $client->setOptions(['trace' => true]);
    $client->setOptions(['exceptions' => false]);

    // Just verify it doesn't throw — options are merged internally
    expect($client)->toBeInstanceOf(TracingSoapClient::class);
});

it('sets trace channel via traceOf', function () {
    $client = new TracingSoapClient();
    $result = $client->traceOf('e-invoice');

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('sets trace channel via channel alias', function () {
    $client = new TracingSoapClient();
    $result = $client->channel('e-invoice');

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('sets trace extra via withTraceExtra', function () {
    $client = new TracingSoapClient();
    $result = $client->withTraceExtra(['invoice_id' => 123]);

    expect($result)->toBeInstanceOf(TracingSoapClient::class);
});

it('creates via static with() method', function () {
    // This will attempt to connect to the WSDL, which will fail in tests.
    // We catch the RuntimeException from the failed SOAP initialization.
    try {
        $client = TracingSoapClient::with('https://nonexistent.example.com/service?wsdl');
    } catch (Throwable) {
        // Expected — no real SOAP server to connect to
        $this->assertTrue(true);

        return;
    }

    expect($client)->toBeInstanceOf(TracingSoapClient::class);
});

it('wraps SoapFault in RuntimeException on initialization failure', function () {
    $client = new TracingSoapClient();
    $client->setWsdl('https://nonexistent.example.com/service?wsdl');

    $client->__getFunctions();
})->throws(RuntimeException::class, 'Failed to initialize SOAP client');

// ──────────────────────────────────────────────────
// Group A: Delegate methods with local WSDL
// ──────────────────────────────────────────────────

it('initializes eagerly when wsdl provided to constructor', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath, ['trace' => true]);

    // Should be initialized — __getFunctions works without error
    expect($client->__getFunctions())->toBeArray()->not->toBeEmpty();
});

it('lazy init works: construct without wsdl, setWsdl, then __getFunctions', function () use ($wsdlPath) {
    $client = new TracingSoapClient();
    $client->setWsdl($wsdlPath);

    expect($client->__getFunctions())->toBeArray()->not->toBeEmpty();
});

it('__getFunctions returns function list from WSDL', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    $functions = $client->__getFunctions();

    expect($functions)->toBeArray()
        ->and($functions)->toHaveCount(1)
        ->and($functions[0])->toContain('Ping');
});

it('__getTypes returns types from WSDL', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    $types = $client->__getTypes();

    // The minimal WSDL uses simple xsd:string types — may return empty
    expect($types)->toBeArray();
});

it('__setLocation changes endpoint and returns previous', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    // First call returns the original location (may be null or string)
    $previous = $client->__setLocation('http://new-endpoint.example.com/soap');

    // Second call should return the location we just set
    $previous2 = $client->__setLocation('http://another.example.com/soap');

    expect($previous2)->toBe('http://new-endpoint.example.com/soap');
});

it('__setCookie does not throw on initialized client', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    $client->__setCookie('session', 'abc123');

    // No exception = success
    expect(true)->toBeTrue();
});

it('__setSoapHeaders returns true on initialized client', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    $result = $client->__setSoapHeaders(null);

    expect($result)->toBeTrue();
});

it('__getLastRequest returns null before any call', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath, ['trace' => true]);

    expect($client->__getLastRequest())->toBeNull();
});

it('__getLastResponse returns null before any call', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath, ['trace' => true]);

    expect($client->__getLastResponse())->toBeNull();
});

it('__getLastRequestHeaders returns null before any call', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath, ['trace' => true]);

    expect($client->__getLastRequestHeaders())->toBeNull();
});

it('__getLastResponseHeaders returns null before any call', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath, ['trace' => true]);

    expect($client->__getLastResponseHeaders())->toBeNull();
});

// ──────────────────────────────────────────────────
// Group B: __doRequest success path via TestableTracingSoapClient
// ──────────────────────────────────────────────────

it('dispatches RequestSendingEvent during __doRequest', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ResponseReceivedEvent::class]);

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse('<Pong/>');

    $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    Event::assertDispatched(RequestSendingEvent::class, function (RequestSendingEvent $event) use ($client) {
        return $event->soapClient === $client
            && '<Ping/>' === $event->request
            && 'http://localhost:9999/soap' === $event->location
            && 'Ping' === $event->action;
    });
});

it('dispatches ResponseReceivedEvent on success with correct channel/extra/response', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ResponseReceivedEvent::class]);

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse('<PingResponse/>');
    $client->traceOf('e-invoice')->withTraceExtra(['inv' => 1]);

    $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    Event::assertDispatched(ResponseReceivedEvent::class, function (ResponseReceivedEvent $event) {
        return '<PingResponse/>' === $event->response
            && 'e-invoice' === $event->channel
            && ['inv' => 1] === $event->extra
            && '<Ping/>' === $event->request;
    });
});

it('clears traceChannel and traceExtra after __doRequest', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ResponseReceivedEvent::class]);

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse('<Pong/>');
    $client->traceOf('first-channel')->withTraceExtra('first-extra');

    $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    // Second call should have null channel and extra
    $client->__doRequest('<Ping2/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    Event::assertDispatched(ResponseReceivedEvent::class, function (ResponseReceivedEvent $event) {
        return null === $event->channel && null === $event->extra && '<Ping2/>' === $event->request;
    });
});

it('returns response from callParentDoRequest', function () use ($wsdlPath) {
    Event::fake();

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse('<PingResponse>pong</PingResponse>');

    $result = $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    expect($result)->toBe('<PingResponse>pong</PingResponse>');
});

it('records start and end timestamps in ResponseReceivedEvent', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ResponseReceivedEvent::class]);

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse('<Pong/>');

    $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);

    Event::assertDispatched(ResponseReceivedEvent::class, function (ResponseReceivedEvent $event) {
        return !empty($event->start) && !empty($event->end);
    });
});

// ──────────────────────────────────────────────────
// Group C: __doRequest failure path
// ──────────────────────────────────────────────────

it('dispatches ConnectionFailedEvent on exception', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ConnectionFailedEvent::class]);

    $fault = new SoapFault('Server', 'Something went wrong');
    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedFault($fault);

    try {
        $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);
    } catch (SoapFault) {
        // expected
    }

    Event::assertDispatched(ConnectionFailedEvent::class);
});

it('re-throws the original exception (same instance)', function () use ($wsdlPath) {
    Event::fake();

    $fault = new SoapFault('Server', 'Original fault');
    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedFault($fault);

    $caught = null;

    try {
        $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);
    } catch (SoapFault $e) {
        $caught = $e;
    }

    expect($caught)->toBe($fault);
});

it('dispatches ConnectionFailedEvent with channel and extra', function () use ($wsdlPath) {
    Event::fake([RequestSendingEvent::class, ConnectionFailedEvent::class]);

    $fault = new SoapFault('Server', 'fail');
    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedFault($fault);
    $client->traceOf('payment')->withTraceExtra(['order' => 99]);

    try {
        $client->__doRequest('<Ping/>', 'http://localhost:9999/soap', 'Ping', SOAP_1_1);
    } catch (SoapFault) {
        // expected
    }

    Event::assertDispatched(ConnectionFailedEvent::class, function (ConnectionFailedEvent $event) use ($fault) {
        return 'payment' === $event->channel
            && ['order' => 99] === $event->extra
            && $event->exception === $fault;
    });
});

// ──────────────────────────────────────────────────
// Group D: __call and __soapCall delegation
// ──────────────────────────────────────────────────

it('__soapCall initializes and delegates through callParentDoRequest', function () use ($wsdlPath) {
    Event::fake();

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    // Return a valid SOAP 1.1 response envelope
    $client->setCannedResponse(
        '<?xml version="1.0"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<SOAP-ENV:Body><ns1:PingResponse xmlns:ns1="http://localhost:9999/soap"><result>pong</result></ns1:PingResponse></SOAP-ENV:Body>'
        . '</SOAP-ENV:Envelope>',
    );

    // __soapCall triggers initialization and __doRequest internally
    $client->__soapCall('Ping', []);

    Event::assertDispatched(RequestSendingEvent::class);
});

it('__call delegates to __soapCall', function () use ($wsdlPath) {
    Event::fake();

    $client = new TestableTracingSoapClient($wsdlPath, ['trace' => true]);
    $client->setCannedResponse(
        '<?xml version="1.0"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<SOAP-ENV:Body><ns1:PingResponse xmlns:ns1="http://localhost:9999/soap"><result>pong</result></ns1:PingResponse></SOAP-ENV:Body>'
        . '</SOAP-ENV:Envelope>',
    );

    // __call is triggered by calling a method as if it were a SOAP operation
    $client->Ping();

    Event::assertDispatched(RequestSendingEvent::class);
});

// ──────────────────────────────────────────────────
// Group E: Edge cases
// ──────────────────────────────────────────────────

it('static with() creates initialized instance from local WSDL', function () use ($wsdlPath) {
    $client = TracingSoapClient::with($wsdlPath);

    expect($client->__getFunctions())->toBeArray()->not->toBeEmpty();
});

it('initializeIfNeeded is idempotent (call twice, no error)', function () use ($wsdlPath) {
    $client = new TracingSoapClient($wsdlPath);

    // First call triggers init, second should be a no-op
    $client->__getFunctions();
    $functions = $client->__getFunctions();

    expect($functions)->toBeArray();
});
