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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Carbon;
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

        // 'success' is not in the known status map so it resolves to UNKNOWN
        self::assertSame('unknown', $normalized['status']);
        self::assertSame('2000000', $normalized['responseCode']);
        self::assertSame('Paid', $normalized['responseMessage']);
        self::assertSame('INV-9001', $normalized['orderId']);
        self::assertSame('TRX-9001', $normalized['transactionId']);
        self::assertSame('REF-9001', $normalized['gatewayReference']);
        self::assertArrayHasKey('raw', $normalized);
        self::assertArrayHasKey('amount', $normalized);
        self::assertArrayHasKey('currency', $normalized);
    }

    public function test_normalize_callback_payload_extracts_amount_and_currency(): void
    {
        $service = new AviagramGatewayService();

        $normalized = $service->normalizeCallbackPayload([
            'orderId' => 'INV-8001',
            'amount' => '99.50',
            'currency' => 'eur-sp',
        ]);

        self::assertSame('99.50', $normalized['amount']);
        self::assertSame('eur-sp', $normalized['currency']);
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

    public function test_gateway_http_options_returns_normalized_options_from_config(): void
    {
        Config::set('aviagram.http', [
            'timeout' => 45,
            'connect_timeout' => 5,
            'proxy' => 'http://proxy.example.com:8080',
            'verify' => false,
        ]);

        $options = (new AviagramGatewayService())->gatewayHttpOptions();

        self::assertSame(45.0, $options['timeout']);
        self::assertSame(5.0, $options['connect_timeout']);
        self::assertSame('http://proxy.example.com:8080', $options['proxy']);
        self::assertFalse($options['verify']);
    }

    public function test_gateway_http_options_supports_protocol_array_proxy(): void
    {
        Config::set('aviagram.http.proxy', [
            'http' => 'http://proxy.example.com:3128',
            'https' => 'http://proxy.example.com:3129',
            'no' => ['localhost'],
        ]);

        $options = (new AviagramGatewayService())->gatewayHttpOptions();

        self::assertSame([
            'http' => 'http://proxy.example.com:3128',
            'https' => 'http://proxy.example.com:3129',
            'no' => ['localhost'],
        ], $options['proxy']);
    }

    public function test_gateway_http_options_omits_null_proxy(): void
    {
        Config::set('aviagram.http', [
            'timeout' => 30,
            'connect_timeout' => 10,
            'proxy' => null,
            'verify' => true,
        ]);

        $options = (new AviagramGatewayService())->gatewayHttpOptions();

        self::assertArrayNotHasKey('proxy', $options);
        self::assertSame(30.0, $options['timeout']);
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

    public function test_store_user_callback_url_persists_key_hash_and_expected_values(): void
    {
        $service = new AviagramGatewayService();
        $rawKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);

        $this->invokePrivateMethod($service, 'storeUserCallbackUrl', [
            'INV-KEY-3001',
            'https://merchant.example/cb',
            $keyHash,
            '250.00',
            'EUR',
        ]);

        /** @var object{callback_key_hash: string|null, callback_key_consumed: int, expected_amount: string|null, expected_currency: string|null}|null $row */
        $row = DB::table('aviagram_transactions')->where('order_id', 'INV-KEY-3001')->first([
            'callback_key_hash',
            'callback_key_consumed',
            'expected_amount',
            'expected_currency',
        ]);

        self::assertNotNull($row);
        self::assertSame($keyHash, $row->callback_key_hash);
        self::assertSame(0, (int) $row->callback_key_consumed);
        self::assertSame('250.00', $row->expected_amount);
        self::assertSame('EUR', $row->expected_currency);
    }

    public function test_resolve_transaction_by_callback_key_returns_matching_row(): void
    {
        $service = new AviagramGatewayService();
        $rawKey = 'test-raw-key-abc123';
        $keyHash = hash('sha256', $rawKey);

        DB::table('aviagram_transactions')->insert([
            'order_id' => 'INV-LOOKUP-4001',
            'callback_key_hash' => $keyHash,
            'callback_key_consumed' => false,
            'expected_amount' => '50.00',
            'expected_currency' => 'EUR',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $transaction = $service->resolveTransactionByCallbackKey($rawKey);

        self::assertNotNull($transaction);
        self::assertSame('INV-LOOKUP-4001', $transaction->order_id);
    }

    public function test_resolve_transaction_by_callback_key_returns_null_for_unknown_key(): void
    {
        $transaction = (new AviagramGatewayService())->resolveTransactionByCallbackKey('no-such-key');

        self::assertNull($transaction);
    }

    public function test_mark_callback_key_consumed_sets_flag_on_transaction(): void
    {
        $service = new AviagramGatewayService();
        DB::table('aviagram_transactions')->insert([
            'order_id' => 'INV-CONSUME-5001',
            'callback_key_consumed' => false,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $service->markCallbackKeyConsumed('INV-CONSUME-5001');

        /** @var object{callback_key_consumed: int}|null $row */
        $row = DB::table('aviagram_transactions')
            ->where('order_id', 'INV-CONSUME-5001')
            ->first(['callback_key_consumed']);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->callback_key_consumed);
    }

    public function test_store_callback_audit_persists_all_audit_fields(): void
    {
        $service = new AviagramGatewayService();
        DB::table('aviagram_transactions')->insert([
            'order_id' => 'INV-AUDIT-6001',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $normalizedPayload = [
            'status' => 'unknown',
            'responseCode' => '2000000',
            'responseMessage' => 'Callback received.',
            'orderId' => 'INV-AUDIT-6001',
            'transactionId' => null,
            'gatewayReference' => null,
            'amount' => '75.00',
            'currency' => 'EUR',
            'raw' => [],
        ];
        $headers = ['content-type' => ['application/json'], 'authorization' => ['Bearer secret']];

        $service->storeCallbackAudit(
            'INV-AUDIT-6001',
            $normalizedPayload,
            'https://gateway.example/api/v1/aviagram/callback/tok',
            '203.0.113.10',
            $headers,
            '{"orderId":"INV-AUDIT-6001","amount":"75.00","currency":"EUR"}',
            true,
            null,
            jobDispatched: true,
        );

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', 'INV-AUDIT-6001')->first();

        self::assertNotNull($row);
        self::assertSame('https://gateway.example/api/v1/aviagram/callback/tok', $row->callback_request_url);
        self::assertSame('203.0.113.10', $row->callback_client_ip);
        self::assertIsString($row->callback_headers);
        self::assertSame($headers, json_decode($row->callback_headers, true));
        self::assertSame('{"orderId":"INV-AUDIT-6001","amount":"75.00","currency":"EUR"}', $row->callback_raw_body);
        self::assertSame(1, (int) $row->callback_validation_passed);
        self::assertNull($row->callback_validation_reason);
        self::assertSame(1, (int) $row->forward_job_dispatched);
        self::assertNotNull($row->forward_job_dispatched_at);
    }

    public function test_store_callback_audit_records_validation_failure_reason(): void
    {
        $service = new AviagramGatewayService();
        DB::table('aviagram_transactions')->insert([
            'order_id' => 'INV-AUDIT-FAIL-7001',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $service->storeCallbackAudit(
            'INV-AUDIT-FAIL-7001',
            [],
            'https://gateway.example/api/v1/aviagram/callback/tok',
            '10.0.0.1',
            [],
            '{"amount":"99.00"}',
            false,
            'Amount mismatch: received 99.00, expected 100.00.',
        );

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', 'INV-AUDIT-FAIL-7001')->first();

        self::assertNotNull($row);
        self::assertSame(0, (int) $row->callback_validation_passed);
        self::assertSame('Amount mismatch: received 99.00, expected 100.00.', $row->callback_validation_reason);
        self::assertSame(0, (int) $row->forward_job_dispatched);
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
            // Callback key security
            $table->string('callback_key_hash')->nullable()->unique();
            $table->boolean('callback_key_consumed')->default(false);
            // Expected values at init time
            $table->string('expected_amount')->nullable();
            $table->string('expected_currency', 10)->nullable();
            // Payment state
            $table->string('status')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_message')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('callback_payload')->nullable();
            // Audit: raw inbound callback request
            $table->text('callback_request_url')->nullable();
            $table->string('callback_client_ip', 45)->nullable();
            $table->json('callback_headers')->nullable();
            $table->text('callback_raw_body')->nullable();
            // Validation outcome
            $table->boolean('callback_validation_passed')->nullable();
            $table->text('callback_validation_reason')->nullable();
            // Synchronous forward (legacy / storeCallbackResult)
            $table->boolean('forwarded')->default(false);
            $table->unsignedSmallInteger('forward_status')->nullable();
            $table->text('forward_error')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            // Async forward job
            $table->boolean('forward_job_dispatched')->default(false);
            $table->timestamp('forward_job_dispatched_at')->nullable();
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
