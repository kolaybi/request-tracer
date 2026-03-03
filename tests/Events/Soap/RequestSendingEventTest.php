<?php

use KolayBi\RequestTracer\Events\Soap\RequestSendingEvent;

it('can be instantiated with all properties', function () {
    $soapClient = Mockery::mock(SoapClient::class);

    $event = new RequestSendingEvent(
        soapClient: $soapClient,
        request: '<request/>',
        location: 'https://soap.example.com/service',
        action: 'http://tempuri.org/GetUser',
    );

    expect($event->soapClient)->toBe($soapClient)
        ->and($event->request)->toBe('<request/>')
        ->and($event->location)->toBe('https://soap.example.com/service')
        ->and($event->action)->toBe('http://tempuri.org/GetUser');
});
