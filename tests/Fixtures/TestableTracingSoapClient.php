<?php

namespace KolayBi\RequestTracer\Tests\Fixtures;

use KolayBi\RequestTracer\Soap\TracingSoapClient;
use SoapFault;

class TestableTracingSoapClient extends TracingSoapClient
{
    private ?string $cannedResponse = null;

    private ?SoapFault $cannedFault = null;

    public function setCannedResponse(string $response): void
    {
        $this->cannedResponse = $response;
        $this->cannedFault = null;
    }

    public function setCannedFault(SoapFault $fault): void
    {
        $this->cannedFault = $fault;
        $this->cannedResponse = null;
    }

    protected function callParentDoRequest(
        string $request,
        string $location,
        string $action,
        int $version,
        bool $oneWay = false,
    ): ?string {
        if (null !== $this->cannedFault) {
            throw $this->cannedFault;
        }

        return $this->cannedResponse ?? '<response/>';
    }
}
