<?php

declare(strict_types=1);

namespace Aviagram\Tests;

use Aviagram\Data\OrderData;
use Aviagram\Services\AviagramGatewayService;
use PHPUnit\Framework\TestCase;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;

final class AviagramGatewayServiceTest extends TestCase
{
    public function test_order_payload_uses_request_fields_by_default(): void
    {
        $service = new AviagramGatewayService();
        $resolveOrderPayload = new \ReflectionMethod(AviagramGatewayService::class, 'resolveOrderPayload');
        $resolveOrderPayload->setAccessible(true);

        $payload = $resolveOrderPayload->invoke($service, new PaymentRequestData(
            gatewayCode: 'aviagram',
            orderId: 'INV-1',
            amount: '15',
            currency: 'EUR',
            callbackUrl: 'https://merchant.example/callback'
        ));

        self::assertIsArray($payload);
        self::assertSame([
            'amount' => '15',
            'currency' => 'eur-sp',
        ], $payload);
    }

    public function test_initiate_payment_wrapper_maps_to_contract_request(): void
    {
        $service = new class extends AviagramGatewayService {
            public ?PaymentRequestData $capturedRequest = null;

            public function initiate(PaymentRequestData $request): PaymentInitResultData
            {
                $this->capturedRequest = $request;

                return new PaymentInitResultData(
                    status: PaymentStatus::PENDING,
                    raw: new DynamicDataBag([
                        'responseCode' => '2000000',
                        'responseMessage' => 'OK',
                    ])
                );
            }
        };

        $response = $service->initiatePayment(new OrderData('15', 'EUR'));

        self::assertSame('2000000', $response['responseCode']);
        self::assertInstanceOf(PaymentRequestData::class, $service->capturedRequest);
        self::assertSame('aviagram', $service->capturedRequest?->gatewayCode());
        self::assertSame('15', $service->capturedRequest?->meta()->requireNumericString('order.amount'));
        self::assertSame('eur-sp', $service->capturedRequest?->meta()->requireString('order.currency'));
    }

    public function test_create_form_is_backward_compatible_alias_of_initiate_payment(): void
    {
        $service = new class extends AviagramGatewayService {
            public bool $initiatePaymentCalled = false;

            public function initiatePayment(OrderData $order): array
            {
                $this->initiatePaymentCalled = true;

                return ['responseCode' => '2000000'];
            }
        };

        $response = $service->createForm(new OrderData('15', 'EUR'));

        self::assertTrue($service->initiatePaymentCalled);
        self::assertSame('2000000', $response['responseCode']);
    }

    public function test_init_response_mapping_preserves_raw_and_extracts_fields(): void
    {
        $service = new class extends AviagramGatewayService {
            public function exposeMap(array $response): PaymentInitResultData
            {
                return $this->mapInitResponseToResult($response);
            }
        };

        $result = $service->exposeMap([
            'responseCode' => '2000000',
            'responseMessage' => 'Initiated',
            'id' => 'TRX-AV-1',
            'formUrl' => 'https://aviagram.app/form/1',
        ]);

        self::assertSame(PaymentStatus::PENDING, $result->status());
        self::assertSame('TRX-AV-1', $result->transactionId());
        self::assertSame('https://aviagram.app/form/1', $result->redirectUrl());
        self::assertSame('2000000', $result->raw()->requireString('responseCode'));
    }

    public function test_initiate_enforces_eur_currency(): void
    {
        $this->expectException(ValidationException::class);

        (new AviagramGatewayService())->initiate(
            new PaymentRequestData(
                gatewayCode: 'aviagram',
                orderId: 'INV-1',
                amount: '15',
                currency: 'USD',
                callbackUrl: 'https://merchant.example/callback'
            )
        );
    }
}
