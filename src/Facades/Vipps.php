<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nesthus\Vipps\Recurring\RecurringApi recurring()
 * @method static \Nesthus\Vipps\Epayment\EpaymentApi epayment()
 * @method static \Nesthus\Vipps\Login\LoginApi login()
 * @method static \Nesthus\Vipps\Webhooks\WebhooksApi webhooks()
 * @method static \Nesthus\Vipps\Auth\TokenProvider tokens()
 * @method static \Nesthus\Vipps\VippsConfig config()
 *
 * @see \Nesthus\Vipps\Vipps
 */
final class Vipps extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nesthus\Vipps\Vipps::class;
    }
}
