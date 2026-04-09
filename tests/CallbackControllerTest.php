<?php

declare(strict_types=1);

namespace Aviagram\Tests;

use Aviagram\Http\Controllers\CallbackController;
use Aviagram\Jobs\ForwardCallbackJob;
use Aviagram\Services\AviagramGatewayService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Minimal Bus dispatcher fake that records dispatched jobs without
 * requiring the illuminate/bus package in standalone test runs.
 */
final class CapturingBusDispatcher implements BusDispatcher
{
    /** @var list<object> */
    public array $dispatched = [];

    public function reset(): void
    {
        $this->dispatched = [];
    }

    public function dispatch(mixed $command): mixed
    {
        $this->dispatched[] = $command;
        return null;
    }

    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        return null;
    }

    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        return null;
    }

    public function dispatchToQueue(mixed $command): void {}

    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void {}

    public function pipeThrough(array $pipes): static
    {
        return $this;
    }

    public function map(array $map): static
    {
        return $this;
    }

    public function hasCommandHandler(mixed $command): bool
    {
        return false;
    }

    public function getCommandHandler(mixed $command): mixed
    {
        return false;
    }

    public function chain($jobs = null): mixed
    {
        return null;
    }
}

final class CallbackControllerTest extends TestCase
{
    private static bool $bootstrapped = false;
    private static CapturingBusDispatcher $bus;

    private const ORDER_ID = 'CTL-ORDER-001';
    private const CALLBACK_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const EXPECTED_AMOUNT = '100.00';
    private const EXPECTED_CURRENCY = 'EUR';
    private const MERCHANT_CALLBACK_URL = 'https://merchant.example/payment-callback';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$bus = new CapturingBusDispatcher();
        self::bootstrapApp();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$bus->reset();
        DB::table('aviagram_transactions')->delete();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_valid_callback_returns_201_and_dispatches_forward_job(): void
    {
        $this->seedTransaction();
        $body = $this->buildBody();

        $response = $this->callController($body);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2010000', $this->decodeResponseCode($response));

        // Job must have been dispatched
        self::assertCount(1, self::$bus->dispatched);
        self::assertInstanceOf(ForwardCallbackJob::class, self::$bus->dispatched[0]);
    }

    public function test_valid_callback_marks_key_as_consumed(): void
    {
        $this->seedTransaction();

        $this->callController($this->buildBody());

        /** @var object{callback_key_consumed: int} $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();
        self::assertSame(1, (int) $row->callback_key_consumed);
    }

    public function test_valid_callback_stores_audit_data_with_validation_passed(): void
    {
        $this->seedTransaction();

        $this->callController($this->buildBody());

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();
        self::assertSame(1, (int) $row->callback_validation_passed);
        self::assertNull($row->callback_validation_reason);
        self::assertSame(1, (int) $row->forward_job_dispatched);
        self::assertNotNull($row->callback_request_url);
        self::assertNotNull($row->callback_client_ip);
        self::assertNotNull($row->callback_raw_body);
    }

    public function test_amounts_normalised_as_equal_for_different_decimal_representations(): void
    {
        $this->seedTransaction(['expected_amount' => '100']);

        // Aviagram sends "100.00" — must equal stored "100"
        $response = $this->callController($this->buildBody(['amount' => '100.00']));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_currency_matched_case_insensitively_and_strips_provider_suffix(): void
    {
        $this->seedTransaction();

        // Aviagram sends "eur-sp"; stored expected is "EUR" — must match
        $response = $this->callController($this->buildBody(['currency' => 'eur-sp']));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Key security
    // -------------------------------------------------------------------------

    public function test_unknown_callback_key_returns_404(): void
    {
        $response = $this->callController($this->buildBody(), callbackKey: 'no-such-key');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('4040002', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    public function test_consumed_key_returns_403(): void
    {
        $this->seedTransaction(['callback_key_consumed' => true]);

        $response = $this->callController($this->buildBody());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('4030001', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    // -------------------------------------------------------------------------
    // Body / payload validation
    // -------------------------------------------------------------------------

    public function test_invalid_json_body_returns_400(): void
    {
        $this->seedTransaction();

        $response = $this->callController('not-valid-json');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('4000002', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    public function test_missing_order_id_in_payload_returns_422(): void
    {
        $this->seedTransaction();

        // Payload has amount and currency but no orderId-extractable field
        $response = $this->callController($this->buildBody([
            'amount' => self::EXPECTED_AMOUNT,
            'currency' => self::EXPECTED_CURRENCY,
            // omit all orderId fields
        ], omitOrderId: true));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('4220001', $this->decodeResponseCode($response));
    }

    public function test_mismatched_order_id_in_payload_returns_422(): void
    {
        $this->seedTransaction();

        // Send a body whose orderId differs from the transaction row's order_id
        $response = $this->callController($this->buildBody(['orderId' => 'WRONG-ORDER-ID']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('4220004', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    public function test_wrong_amount_returns_422(): void
    {
        $this->seedTransaction();

        $response = $this->callController($this->buildBody(['amount' => '99.00']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('4220002', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    public function test_wrong_currency_returns_422(): void
    {
        $this->seedTransaction();

        $response = $this->callController($this->buildBody(['currency' => 'USD']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('4220003', $this->decodeResponseCode($response));
        self::assertCount(0, self::$bus->dispatched);
    }

    public function test_wrong_amount_stores_failure_audit(): void
    {
        $this->seedTransaction();

        $this->callController($this->buildBody(['amount' => '1.00']));

        /** @var object $row */
        $row = DB::table('aviagram_transactions')->where('order_id', self::ORDER_ID)->first();
        self::assertSame(0, (int) $row->callback_validation_passed);
        self::assertStringContainsString('Amount mismatch', (string) $row->callback_validation_reason);
    }

    // -------------------------------------------------------------------------
    // Named route
    // -------------------------------------------------------------------------

    public function test_named_route_definition_includes_callback_key_segment(): void
    {
        // The route file is the source of truth for the route shape.
        // Verify it registers the correct URI pattern and name without needing
        // a full HTTP layer.
        $routeFile = (string) file_get_contents(__DIR__ . '/../routes/routes.php');
        self::assertStringContainsString('{callbackKey}', $routeFile);
        self::assertStringContainsString('aviagram.callback', $routeFile);
        self::assertStringContainsString('aviagram/callback/{callbackKey}', $routeFile);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inserts a transaction row simulating what initiate() would have created.
     *
     * @param array<string, mixed> $overrides
     */
    private function seedTransaction(array $overrides = []): void
    {
        DB::table('aviagram_transactions')->insert(array_replace([
            'order_id' => self::ORDER_ID,
            'callback_url' => self::MERCHANT_CALLBACK_URL,
            'callback_key_hash' => hash('sha256', self::CALLBACK_KEY),
            'callback_key_consumed' => false,
            'expected_amount' => self::EXPECTED_AMOUNT,
            'expected_currency' => self::EXPECTED_CURRENCY,
            'created_at' => \Illuminate\Support\Carbon::now(),
            'updated_at' => \Illuminate\Support\Carbon::now(),
        ], $overrides));
    }

    /**
     * Builds a valid JSON body as a string.
     *
     * @param array<string, mixed> $overrides
     */
    private function buildBody(array $overrides = [], bool $omitOrderId = false): string
    {
        $base = [
            'orderId' => self::ORDER_ID,
            'amount' => self::EXPECTED_AMOUNT,
            'currency' => self::EXPECTED_CURRENCY,
        ];
        if ($omitOrderId) {
            unset($base['orderId']);
        }
        return (string) json_encode(array_replace($base, $overrides));
    }

    private function callController(
        string $body,
        string $callbackKey = self::CALLBACK_KEY,
    ): \Illuminate\Http\JsonResponse {
        $request = Request::create(
            uri: '/api/v1/aviagram/callback/' . $callbackKey,
            method: 'POST',
            content: $body,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
        );

        return (new CallbackController())->handle($request, $callbackKey, new AviagramGatewayService());
    }

    private function decodeResponseCode(\Illuminate\Http\JsonResponse $response): string
    {
        /** @var array{responseCode?: string} $data */
        $data = json_decode($response->getContent(), true);
        return $data['responseCode'] ?? '';
    }

    private static function bootstrapApp(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $app = new Container();
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'testbench_ctrl',
                'connections' => [
                    'testbench_ctrl' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
        ]));

        Facade::setFacadeApplication($app);
        // Clear any cached facade roots from other test classes running in the same process.
        Facade::clearResolvedInstances();

        $databaseManager = new DatabaseManager($app, new ConnectionFactory($app));
        $app->instance('db', $databaseManager);
        $app->instance('db.connection', $databaseManager->connection('testbench_ctrl'));

        // Bind the Bus fake so Bus::dispatch() in the controller is intercepted.
        $app->instance(BusDispatcher::class, self::$bus);

        DB::connection('testbench_ctrl')->getSchemaBuilder()->create('aviagram_transactions', function (Blueprint $table): void {
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
            $table->timestamp('forwarded_at')->nullable();
            $table->boolean('forward_job_dispatched')->default(false);
            $table->timestamp('forward_job_dispatched_at')->nullable();
            $table->timestamps();
        });

        self::$bootstrapped = true;
    }
}
