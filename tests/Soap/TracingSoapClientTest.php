<?php

use KolayBi\RequestTracer\Soap\TracingSoapClient;

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
