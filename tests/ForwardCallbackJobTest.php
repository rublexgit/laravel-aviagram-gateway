<?php

declare(strict_types=1);

namespace Aviagram\Tests;

use Aviagram\Jobs\ForwardCallbackJob;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Rublex\CoreGateway\Data\CallbackForwardResultData;
use Rublex\CoreGateway\Data\PaymentOutcomeData;
use RuntimeException;

final class ForwardCallbackJobTest extends TestCase
{
    private static bool $bootstrapped = false;

    private const ORDER_ID = 'JOB-ORDER-001';
    private const CALLBACK_URL = 'https://merchant.example/payment-callback';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::bootstrapApp();
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('aviagram_transactions')->delete();
    }

    // -------------------------------------------------------------------------
    // Forwarded payload shape
    // -------------------------------------------------------------------------

    public function test_forwarded_payload_matches_payment_outcome_to_array_contract(): void
    {
        $outcome = $this->makeOutcome();

        // The shape forwarded to the merchant is exactly PaymentOutcomeData::toArray()
        self::assertSame(
            ['orderId', 'status', 'currency', 'amount', 'errorMessage', 'gatewayCode', 'occurredAt', 'raw'],
            array_keys($outcome->toArray()),
        );
        self::assertSame(self::ORDER_ID, $outcome->toArray()['orderId']);
        self::assertSame('success', $outcome->toArray()['status']);
        self::assertSame('EUR', $outcome->toArray()['currency']);
        self::assertSame('100.00', $outcome->toArray()['amount']);
        self::assertSame('aviagram', $outcome->toArray()['gatewayCode']);
    }

    // -------------------------------------------------------------------------
    // Successful forward
    // -------------------------------------------------------------------------

    public function test_successful_forward_persists_delivery_result_fields(): void
    {
        $this->seedTransaction();

        $this->makeJob(CallbackForwardResultData::fromHttpResponse(
            successful: true,
            httpStatus: 200,
            responseBody: '{"received":true}',
            respondedAt: new DateTimeImmutable('2025-10-09T18:42:00+00:00'),
        ))->handle();

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();

        self::assertSame(1, (int) $row->forwarded);
        self::assertSame(200, (int) $row->forward_status);
        self::assertNull($row->forward_error);
        self::assertSame('{"received":true}', $row->forward_response_body);
        self::assertNotNull($row->forwarded_at);
    }

    // -------------------------------------------------------------------------
    // Non-2xx HTTP failure
    // -------------------------------------------------------------------------

    public function test_non_2xx_response_is_persisted_as_not_forwarded(): void
    {
        $this->seedTransaction();

        $this->makeJob(CallbackForwardResultData::fromHttpResponse(
            successful: false,
            httpStatus: 503,
            responseBody: 'Service Unavailable',
            respondedAt: new DateTimeImmutable(),
        ))->handle();

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();

        self::assertSame(0, (int) $row->forwarded);
        self::assertSame(503, (int) $row->forward_status);
        self::assertNull($row->forward_error);
        self::assertSame('Service Unavailable', $row->forward_response_body);
        self::assertNull($row->forwarded_at);
    }

    // -------------------------------------------------------------------------
    // Transport-level exception
    // -------------------------------------------------------------------------

    public function test_exception_persists_error_message_and_rethrows(): void
    {
        $this->seedTransaction();

        try {
            $this->makeJob(new RuntimeException('Connection timed out.'))->handle();
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('Connection timed out.', $e->getMessage());
        }

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();

        self::assertSame(0, (int) $row->forwarded);
        self::assertNull($row->forward_status);
        self::assertSame('Connection timed out.', $row->forward_error);
        self::assertNull($row->forward_response_body);
        self::assertNull($row->forwarded_at);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeOutcome(): PaymentOutcomeData
    {
        return new PaymentOutcomeData(
            orderId: self::ORDER_ID,
            status: 'success',
            currency: 'EUR',
            amount: '100.00',
            errorMessage: null,
            gatewayCode: 'aviagram',
            occurredAt: new DateTimeImmutable('2025-10-09T18:42:00+00:00'),
            raw: ['method' => 'CARD', 'type' => 'TOPUP', 'createdAt' => '2025-10-09T18:42:00.000Z'],
        );
    }

    /**
     * Creates a job whose sendForwardRequest() returns the given DTO,
     * or throws the given Throwable, without touching the HTTP facade.
     */
    private function makeJob(CallbackForwardResultData|RuntimeException $deliveryResult): ForwardCallbackJob
    {
        return new class(
            self::ORDER_ID,
            self::CALLBACK_URL,
            $this->makeOutcome(),
            $deliveryResult,
        ) extends ForwardCallbackJob {
            public function __construct(
                string $orderId,
                string $callbackUrl,
                PaymentOutcomeData $outcome,
                private readonly CallbackForwardResultData|\Throwable $deliveryResult,
            ) {
                parent::__construct($orderId, $callbackUrl, $outcome);
            }

            protected function sendForwardRequest(): CallbackForwardResultData
            {
                if ($this->deliveryResult instanceof \Throwable) {
                    throw $this->deliveryResult;
                }

                return $this->deliveryResult;
            }
        };
    }

    private function seedTransaction(): void
    {
        DB::table('aviagram_transactions')->insert([
            'order_id'   => self::ORDER_ID,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private static function bootstrapApp(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $app = new Container();
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'testbench_job',
                'connections' => [
                    'testbench_job' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $databaseManager = new DatabaseManager($app, new ConnectionFactory($app));
        $app->instance('db', $databaseManager);
        $app->instance('db.connection', $databaseManager->connection('testbench_job'));

        DB::connection('testbench_job')->getSchemaBuilder()->create('aviagram_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('callback_url')->nullable();
            $table->string('callback_key_hash')->nullable()->unique();
            $table->boolean('callback_key_consumed')->default(false);
            $table->string('expected_amount')->nullable();
            $table->string('expected_currency', 10)->nullable();
            $table->string('status')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_message')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->text('callback_request_url')->nullable();
            $table->string('callback_client_ip', 45)->nullable();
            $table->json('callback_headers')->nullable();
            $table->text('callback_raw_body')->nullable();
            $table->boolean('callback_validation_passed')->nullable();
            $table->text('callback_validation_reason')->nullable();
            $table->boolean('forwarded')->default(false);
            $table->unsignedSmallInteger('forward_status')->nullable();
            $table->text('forward_error')->nullable();
            $table->text('forward_response_body')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->boolean('forward_job_dispatched')->default(false);
            $table->timestamp('forward_job_dispatched_at')->nullable();
            $table->timestamps();
        });

        self::$bootstrapped = true;
    }
}
