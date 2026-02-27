<?php

namespace KolayBi\RequestTracer\Contracts;

interface TraceContextProvider
{
    public function tenantId(): int|string|null;

    public function userId(): int|string|null;

    public function clientIp(): ?string;

    public function serverIdentifier(): ?string;
}
