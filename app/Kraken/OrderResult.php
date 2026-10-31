<?php

namespace App\Kraken;

use App\Models\KrakenOrder;

final readonly class OrderResult
{
    private function __construct(
        public bool $ok,
        public ?KrakenOrder $order = null,
        public ?string $errorCode = null,
    ) {
    }

    public static function success(KrakenOrder $order): self
    {
        return new self(true, order: $order);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, errorCode: $errorCode);
    }
}
