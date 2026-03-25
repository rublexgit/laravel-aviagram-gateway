# Laravel Aviagram Gateway Usage

## Installation

1. Install the package via Composer:

```bash
composer require rublex/laravel-aviagram-gateway
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --provider="Aviagram\AviagramServiceProvider" --tag="aviagram-config"
```

3. Add your Aviagram credentials to your `.env` file:

```env
AVIAGRAM_BASE_URL=https://aviagram.app
AVIAGRAM_CLIENT_ID=
AVIAGRAM_CLIENT_SECRET=
AVIAGRAM_USER_AGENT=aviagram-laravel-gateway/1.0.0
AVIAGRAM_TIMEOUT=30
```

## Usage

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

## Currency rules

- Only `EUR` is accepted.
- The package automatically maps `EUR` to Aviagram API currency value `eur-sp`.
