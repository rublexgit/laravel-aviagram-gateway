<?php
declare(strict_types=1);

namespace Aviagram\Data;

use Rublex\CoreGateway\Exceptions\ValidationException;

class OrderData
{
    private const SUPPORTED_CURRENCY = 'EUR';
    private const API_CURRENCY = 'eur-sp';

    public function __construct(
        private readonly string $id,
        private readonly string $amount,
        private readonly string $currency = self::SUPPORTED_CURRENCY,
        private readonly ?string $paymentMethod = null,
    ) {
        $this->validate();
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Serialised form fed into the AviagramGatewayService meta.order bag.
     *
     * `payment_method` is only emitted when the caller explicitly picked one;
     * an absent value preserves the pre-update behaviour where Aviagram's
     * hosted form lets the customer pick the channel.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => self::API_CURRENCY,
        ];

        if ($this->paymentMethod !== null && trim($this->paymentMethod) !== '') {
            $payload['payment_method'] = $this->paymentMethod;
        }

        return $payload;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    public function paymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    private function validate(): void
    {
        if ($this->id === '') {
            throw new ValidationException('Order id is required.');
        }

        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new ValidationException('Order amount must be a valid positive number.');
        }

        if (strtoupper($this->currency) !== self::SUPPORTED_CURRENCY) {
            throw new ValidationException('Only EUR currency is supported.');
        }
    }
}
