<?php

namespace Aviagram\Data;

use InvalidArgumentException;

class OrderData
{
    private const SUPPORTED_CURRENCY = 'EUR';
    private const API_CURRENCY = 'eur-sp';

    public function __construct(
        private readonly string $amount,
        private readonly string $currency = self::SUPPORTED_CURRENCY
    ) {
        $this->validate();
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => self::API_CURRENCY,
        ];
    }

    private function validate(): void
    {
        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new InvalidArgumentException('Order amount must be a valid positive number.');
        }

        if (strtoupper($this->currency) !== self::SUPPORTED_CURRENCY) {
            throw new InvalidArgumentException('Only EUR currency is supported.');
        }
    }
}
