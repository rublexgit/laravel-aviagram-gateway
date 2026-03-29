<?php

declare(strict_types=1);

namespace Aviagram\Tests;

use Aviagram\Data\OrderData;
use Aviagram\Services\AviagramGatewayService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;

final class AviagramGatewayServiceTest extends TestCase
{
    private static bool $databaseBootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::bootstrapDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootstrapDatabase();

        DB::table('aviagram_transactions')->delete();
    }

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
                    transactionId: 'TRX-AV-1001',
                    redirectUrl: 'https://aviagram.app/form/INV-1001',
                    gatewayReference: 'REF-AV-1001',
                    meta: new DynamicDataBag([
                        'responseCode' => '2000000',
                        'responseMessage' => 'OK',
                    ]),
                    raw: new DynamicDataBag([
                        'providerField' => 'providerValue',
                    ])
                );
            }
        };

        $response = $service->initiatePayment(
            new OrderData('INV-1001', '15', 'EUR'),
            'https://merchant.example/final-callback'
        );

        self::assertSame([
            'status' => 'pending',
            'responseCode' => '2000000',
            'responseMessage' => 'OK',
            'orderId' => 'INV-1001',
            'transactionId' => 'TRX-AV-1001',
            'redirect_url' => 'https://aviagram.app/form/INV-1001',
            'gatewayReference' => 'REF-AV-1001',
            'raw' => [
                'providerField' => 'providerValue',
            ],
        ], $response);
        self::assertInstanceOf(PaymentRequestData::class, $service->capturedRequest);
        self::assertSame('aviagram', $service->capturedRequest?->gatewayCode());
        self::assertSame('INV-1001', $service->capturedRequest?->meta()->requireString('order.id'));
        self::assertSame('15', $service->capturedRequest?->meta()->requireNumericString('order.amount'));
        self::assertSame('eur-sp', $service->capturedRequest?->meta()->requireString('order.currency'));
        self::assertSame('https://merchant.example/final-callback', $service->capturedRequest?->callbackUrl());
    }

    public function test_init_response_mapping_preserves_raw_and_extracts_fields(): void
    {
        $service = new class extends AviagramGatewayService {
            public function exposeMap(array $response): PaymentInitResultData
            {
                return $this->mapInitResponseToResult($response);
            }
        };

        /** @var mixed $service */
        $result = $service->exposeMap([
            'responseCode' => '2000000',
            'responseMessage' => 'Initiated',
            'orderId' => 'TRX-AV-1',
            'redirect_url' => 'https://aviagram.app/form/1',
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

    public function test_normalize_callback_payload_maps_standard_fields(): void
    {
        $service = new AviagramGatewayService();

        $normalized = $service->normalizeCallbackPayload([
            'responseCode' => '2000000',
            'responseMessage' => 'Paid',
            'status' => 'success',
            'order' => [
                'id' => 'INV-9001',
            ],
            'transactionId' => 'TRX-9001',
            'reference' => 'REF-9001',
        ]);

        self::assertSame('success', $normalized['status']);
        self::assertSame('2000000', $normalized['responseCode']);
        self::assertSame('Paid', $normalized['responseMessage']);
        self::assertSame('INV-9001', $normalized['orderId']);
        self::assertSame('TRX-9001', $normalized['transactionId']);
        self::assertSame('REF-9001', $normalized['gatewayReference']);
        self::assertArrayHasKey('raw', $normalized);
    }

    public function test_store_init_transaction_persists_invalid_json_response_without_array_to_string_error(): void
    {
        $service = new AviagramGatewayService();
        $responsePayload = [
            'responseCode' => '502',
            'responseMessage' => 'Invalid JSON response from Aviagram.',
        ];

        $this->invokePrivateMethod($service, 'storeInitTransaction', ['INV-ERR-1001', $responsePayload]);

        /** @var object{response_code: string|null, response_message: string|null, provider_payload: string|null}|null $row */
        $row = DB::table('aviagram_transactions')->where('order_id', 'INV-ERR-1001')->first([
            'response_code',
            'response_message',
            'provider_payload',
        ]);

        self::assertNotNull($row);
        self::assertSame('502', $row->response_code);
        self::assertSame('Invalid JSON response from Aviagram.', $row->response_message);
        self::assertIsString($row->provider_payload);
        self::assertSame($responsePayload, json_decode($row->provider_payload, true));
    }

    public function test_store_callback_result_encodes_callback_payload_json_column(): void
    {
        $service = new AviagramGatewayService();
        $callbackPayload = [
            'status' => 'success',
            'responseCode' => '2000000',
            'responseMessage' => 'Paid',
            'transactionId' => 'TRX-2001',
            'gatewayReference' => 'REF-2001',
        ];

        $service->storeCallbackResult('INV-CB-2001', $callbackPayload, true, 200);

        /** @var object{response_code: string|null, response_message: string|null, callback_payload: string|null}|null $row */
        $row = DB::table('aviagram_transactions')->where('order_id', 'INV-CB-2001')->first([
            'response_code',
            'response_message',
            'callback_payload',
        ]);

        self::assertNotNull($row);
        self::assertSame('2000000', $row->response_code);
        self::assertSame('Paid', $row->response_message);
        self::assertIsString($row->callback_payload);
        self::assertSame($callbackPayload, json_decode($row->callback_payload, true));
    }

    private static function bootstrapDatabase(): void
    {
        if (self::$databaseBootstrapped) {
            return;
        }

        $app = new Container();
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'testbench',
                'connections' => [
                    'testbench' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
        ]));

        Facade::setFacadeApplication($app);

        $databaseManager = new DatabaseManager($app, new ConnectionFactory($app));
        $app->instance('db', $databaseManager);
        $app->instance('db.connection', $databaseManager->connection('testbench'));

        DB::connection('testbench')->getSchemaBuilder()->create('aviagram_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('callback_url')->nullable();
            $table->string('status')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_message')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->boolean('forwarded')->default(false);
            $table->unsignedSmallInteger('forward_status')->nullable();
            $table->text('forward_error')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();
        });

        self::$databaseBootstrapped = true;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(object $instance, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($instance, $arguments);
    }
}
