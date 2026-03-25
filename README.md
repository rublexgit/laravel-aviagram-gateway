# Laravel Aviagram Gateway

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/rublex/laravel-aviagram-gateway)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A Laravel payment gateway package for Aviagram integration.

## Features

- Payment initiation via Aviagram API
- EUR-only currency enforcement
- Configurable via environment variables
- Laravel facade support

## Installation

```bash
composer require rublex/laravel-aviagram-gateway
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Aviagram\AviagramServiceProvider" --tag="aviagram-config"
```

Add credentials to your `.env` file:

```env
AVIAGRAM_BASE_URL=https://aviagram.app
AVIAGRAM_CLIENT_ID=
AVIAGRAM_CLIENT_SECRET=
```

## Quick Start

```php
use Aviagram\Data\OrderData;
use Aviagram\Facades\Aviagram;

$response = Aviagram::initiatePayment(
    new OrderData(
        amount: '15',
        currency: 'EUR',
    )
);
```

## Contract-Based Usage

```php
use Aviagram\Services\AviagramGatewayService;
use Rublex\CoreGateway\Data\PaymentRequestData;

$gateway = app(AviagramGatewayService::class);

$result = $gateway->initiate(new PaymentRequestData(
    gatewayCode: $gateway->code(),
    orderId: 'INV-1',
    amount: '15',
    currency: 'EUR',
    callbackUrl: 'https://merchant.example/callback'
));
```

## Backward Compatibility

- `initiatePayment(OrderData)` is available as the primary facade wrapper for payment initiation.
- `createForm(OrderData)` is deprecated and remains available only as a compatibility proxy to `initiatePayment(OrderData)`.
- EUR currency constraints remain enforced.

## Documentation

For installation and usage instructions, see [USAGE.md](USAGE.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
