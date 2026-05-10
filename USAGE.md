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
```

## Usage

```php
use Aviagram\Data\OrderData;
use Aviagram\Facades\Aviagram;

$response = Aviagram::initiatePayment(
    new OrderData(
        id: 'INV-1774369486',
        amount: '15',
        currency: 'EUR',
    ),
    userCallbackUrl: 'https://your-app.example.com/finpay/final-callback'
);

// Unified wrapper response shape:
// [
//     'status' => 'pending',
//     'responseCode' => '2000000',
//     'responseMessage' => 'Initiated',
//     'orderId' => 'INV-1774369486',
//     'transactionId' => 'TRX-123',
//     'redirect_url' => 'https://aviagram.app/form/...',
//     'gatewayReference' => 'REF-123',
//     'raw' => [/* full provider payload */],
// ]
```

Provider-specific fields are preserved under `raw`.

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

## Currency rules

- Only `EUR` is accepted.
- The package automatically maps `EUR` to Aviagram API currency value `eur-sp`.

## Choosing a payment channel

Aviagram exposes a `payment_method` field on `POST /api/payment/createForm`.
The driver forwards whichever value the caller puts in `meta.payment_method`,
matched against `AviagramGatewayService::PAYMENT_METHODS`:

```
card, googlepay, applepay, sepa
```

Examples:

```php
// Contract-based
$gateway->initiate(new PaymentRequestData(
    gatewayCode: $gateway->code(),
    orderId: 'INV-1',
    amount: '15',
    currency: 'EUR',
    callbackUrl: 'https://merchant.example/callback',
    meta: new DynamicDataBag(['payment_method' => 'googlepay']),
));

// Facade
Aviagram::initiatePayment(
    new OrderData(id: 'INV-1', amount: '15', currency: 'EUR', paymentMethod: 'applepay'),
    userCallbackUrl: 'https://merchant.example/callback',
);
```

If `meta.payment_method` is omitted (or unknown), the driver does **not** set
`payment_method` on the createForm body — Aviagram's hosted form lets the
customer pick the channel, matching pre-update behaviour.

## Migration note

- `initiatePayment(OrderData, string $userCallbackUrl)` is available as the primary facade wrapper for payment initiation.
- New integrations should use the shared core contract DTOs directly.
