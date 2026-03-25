# Laravel Aviagram Gateway

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/rublex/laravel-aviagram-gateway)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A Laravel payment gateway package for Aviagram integration.

## Features

- Create payment form via Aviagram API
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
AVIAGRAM_USER_AGENT=aviagram-laravel-gateway/1.0.0
AVIAGRAM_TIMEOUT=30
```

## Quick Start

```php
use Aviagram\Data\OrderData;
use Aviagram\Facades\Aviagram;

$response = Aviagram::createForm(
    new OrderData(
        amount: '15',
        currency: 'EUR',
    )
);
```

## Documentation

For installation and usage instructions, see [USAGE.md](USAGE.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
