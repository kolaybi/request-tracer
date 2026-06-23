<?php

namespace KolayBi\RequestTracer\Tests\Fixtures;

enum StringChannel: string
{
    case Payment = 'payment';
    case EInvoice = 'e-invoice';
}
