<?php

namespace KolayBi\RequestTracer\Contracts;

class NullContextProvider implements TraceContextProvider
{
    public function tenantId(): int|string|null
    {
        return null;
    }

    public function userId(): int|string|null
    {
        return null;
    }

    public function clientIp(): ?string
    {
        return request()?->ip();
    }

    public function serverIdentifier(): ?string
    {
        return gethostname() ?: null;
    }
}
