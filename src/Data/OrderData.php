<?php
declare(strict_types=1);

namespace Aviagram\Data;

use Rublex\CoreGateway\Exceptions\ValidationException;

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

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    private function validate(): void
    {
        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new ValidationException('Order amount must be a valid positive number.');
        }

        if (strtoupper($this->currency) !== self::SUPPORTED_CURRENCY) {
            throw new ValidationException('Only EUR currency is supported.');
        }
    }
}
