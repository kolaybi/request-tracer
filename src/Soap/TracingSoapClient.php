<?php

namespace KolayBi\RequestTracer\Soap;

use KolayBi\RequestTracer\Events\Soap\ConnectionFailedEvent;
use KolayBi\RequestTracer\Events\Soap\RequestSendingEvent;
use KolayBi\RequestTracer\Events\Soap\ResponseReceivedEvent;
use KolayBi\RequestTracer\Support\Timestamp;
use Override;
use RuntimeException;
use SoapClient;
use SoapFault;
use Throwable;

class TracingSoapClient extends SoapClient
{
    protected ?string $wsdl = null;

    protected array $options = [];

    protected bool $initialized = false;

    protected ?string $traceChannel = null;

    protected array|string|null $traceExtra = null;

    public function __construct(?string $wsdl = null, array $options = [])
    {
        $this->wsdl = $wsdl;
        $this->options = $options;

        if (null !== $wsdl) {
            parent::__construct($wsdl, $options);
            $this->initialized = true;
        }
    }

    /**
     * @throws SoapFault
     */
    #[Override]
    public function __call(string $name, array $args): mixed
    {
        $this->initializeIfNeeded();

        return $this->__soapCall($name, $args);
    }

    /**
     * @throws SoapFault
     */
    #[Override]
    public function __soapCall(
        string $name,
        array $args,
        ?array $options = null,
        $inputHeaders = null,
        &$outputHeaders = null,
    ): mixed {
        $this->initializeIfNeeded();

        return parent::__soapCall($name, $args, $options, $inputHeaders, $outputHeaders);
    }

    #[Override]
    public function __setSoapHeaders($headers = null): bool
    {
        $this->initializeIfNeeded();

        return parent::__setSoapHeaders($headers);
    }

    #[Override]
    public function __setLocation(?string $location = null): ?string
    {
        $this->initializeIfNeeded();

        return parent::__setLocation($location);
    }

    #[Override]
    public function __setCookie(string $name, ?string $value = null): void
    {
        $this->initializeIfNeeded();

        parent::__setCookie($name, $value);
    }

    #[Override]
    public function __getLastRequest(): ?string
    {
        $this->initializeIfNeeded();

        return parent::__getLastRequest();
    }

    #[Override]
    public function __getLastResponse(): ?string
    {
        $this->initializeIfNeeded();

        return parent::__getLastResponse();
    }

    #[Override]
    public function __getLastRequestHeaders(): ?string
    {
        $this->initializeIfNeeded();

        return parent::__getLastRequestHeaders();
    }

    #[Override]
    public function __getLastResponseHeaders(): ?string
    {
        $this->initializeIfNeeded();

        return parent::__getLastResponseHeaders();
    }

    #[Override]
    public function __getFunctions(): ?array
    {
        $this->initializeIfNeeded();

        return parent::__getFunctions();
    }

    #[Override]
    public function __getTypes(): ?array
    {
        $this->initializeIfNeeded();

        return parent::__getTypes();
    }

    /**
     * @throws Throwable
     */
    #[Override]
    public function __doRequest(
        string $request,
        string $location,
        string $action,
        int $version,
        bool $oneWay = false,
    ): ?string {
        $this->initializeIfNeeded();

        $channel = $this->traceChannel;
        $extra = $this->traceExtra;
        $this->traceChannel = null;
        $this->traceExtra = null;

        $start = Timestamp::now();

        RequestSendingEvent::dispatch($this, $request, $location, $action);

        try {
            $response = parent::__doRequest($request, $location, $action, $version, $oneWay);

            $end = Timestamp::now();

            ResponseReceivedEvent::dispatch($this, $request, $location, $action, $response, $channel, $extra, $start, $end);

            return $response;
        } catch (Throwable $throwable) {
            $end = Timestamp::now();

            ConnectionFailedEvent::dispatch($this, $request, $location, $action, $throwable, $channel, $extra, $start, $end);

            throw $throwable;
        }
    }

    public function traceOf(?string $channel): static
    {
        $this->traceChannel = $channel;

        return $this;
    }

    public function channel(?string $channel): static
    {
        return $this->traceOf($channel);
    }

    public function withTraceExtra(array|string|null $extra): static
    {
        $this->traceExtra = $extra;

        return $this;
    }

    public function setWsdl(string $wsdl): static
    {
        $this->wsdl = $wsdl;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function setOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    protected function initializeIfNeeded(): void
    {
        if (!$this->initialized) {
            if (null === $this->wsdl) {
                throw new RuntimeException('WSDL URL must be set before making SOAP calls');
            }

            try {
                parent::__construct($this->wsdl, $this->options);
                $this->initialized = true;
            } catch (SoapFault $e) {
                throw new RuntimeException(
                    "Failed to initialize SOAP client: {$e->getMessage()}",
                    $e->getCode(),
                    $e,
                );
            }
        }
    }

    /**
     * @throws SoapFault
     */
    public static function with(string $wsdl, array $options = []): static
    {
        return new static($wsdl, $options);
    }
}
