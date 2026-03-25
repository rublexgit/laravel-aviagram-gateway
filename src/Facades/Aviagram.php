<?php
declare(strict_types=1);

namespace Aviagram\Facades;

use Aviagram\Services\AviagramGatewayService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Rublex\CoreGateway\Data\PaymentInitResultData initiate(\Rublex\CoreGateway\Data\PaymentRequestData $request)
 * @method static array initiatePayment(\Aviagram\Data\OrderData $order, string $userCallbackUrl)
 *
 * @see AviagramGatewayService
 */
class Aviagram extends Facade
{
    final public const VERSION = '1.0.0';

    protected static function getFacadeAccessor(): string
    {
        return AviagramGatewayService::class;
    }
}
