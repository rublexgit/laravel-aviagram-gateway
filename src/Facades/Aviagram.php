<?php

namespace Aviagram\Facades;

use Aviagram\Services\AviagramGatewayService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array createForm(\Aviagram\Data\OrderData $order)
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
