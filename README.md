# nesthus/vipps-laravel

Unofficial Laravel bridge for [nesthus/vipps-php](https://github.com/ekstremedia/vipps-php):
config with enforced timeouts, a `Vipps` facade, webhook middleware and events,
a `vipps:webhooks` artisan command, and a Socialite driver for Vipps Login.

[![CI](https://github.com/ekstremedia/vipps-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/ekstremedia/vipps-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/nesthus/vipps-laravel)](https://packagist.org/packages/nesthus/vipps-laravel)
[![Downloads](https://img.shields.io/packagist/dt/nesthus/vipps-laravel)](https://packagist.org/packages/nesthus/vipps-laravel)
[![License](https://img.shields.io/packagist/l/nesthus/vipps-laravel)](LICENSE)

> [!IMPORTANT]
> **This is an unofficial package.** It is not affiliated with, endorsed or
> supported by Vipps MobilePay AS. *Vipps* and *MobilePay* are trademarks of
> Vipps MobilePay AS. When presenting the payment option to your users, follow
> the official brand guidelines: <https://brand.vippsmobilepay.com/>.

## What the bridge adds

The SDK stays framework-free; this package does the Laravel wiring so your app
never touches PSR plumbing:

- **One `Nesthus\Vipps\Vipps` singleton** in the container, built from
  `config/vipps.php`, behind a facade. Bindings are lazy — an app with no
  Vipps credentials boots fine and only fails (loudly, with the missing field
  named) when something actually resolves Vipps.
- **Mandatory transport deadlines.** The provider *refuses* non-positive
  timeouts at resolve time. Guzzle waits forever by default, and a payment
  call with no deadline can wedge a queue worker for good — so the deadline is
  not an option you may set, it is config you may only tune.
- **Access tokens shared between workers** through a PSR-16 bridge over a
  Laravel cache store, instead of every queue worker minting its own.
- **A one-line webhook receiver** — signature-verified deliveries become
  Laravel events; forged ones are rejected before your code runs.
- **`php artisan vipps:webhooks`** to register/list/delete subscriptions.
- **Socialite driver `vipps`** for Vipps Login (OIDC).

This README covers the Laravel surface. For API semantics — reserve/capture,
agreements and charges, idempotency keys, error handling — the
[SDK README](https://github.com/ekstremedia/vipps-php#readme) is the reference,
and everything there applies unchanged here.

## Requirements

- PHP **8.3+**
- Laravel **11 or 12**
- `laravel/socialite` ^5.12 (installed automatically — it is a dependency)

## Install

```bash
composer require nesthus/vipps-laravel
```

Until both packages are published on Packagist, point Composer at the GitHub
repositories first. **Both entries are required**: Composer only reads the
*root* package's `repositories`, so the bridge's own vcs entry for the SDK
does not carry over into your app.

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ekstremedia/vipps-laravel" },
        { "type": "vcs", "url": "https://github.com/ekstremedia/vipps-php" }
    ]
}
```

```bash
composer require nesthus/vipps-laravel:dev-main
```

The service provider and the `Vipps` facade alias are auto-discovered.

## Configuration

```bash
php artisan vendor:publish --tag=vipps-config
```

All four credential values come from the sales unit's developer section in the
[merchant portal](https://portal.vippsmobilepay.com/) — one set per
environment. Test keys only work against the test host and vice versa, so
`VIPPS_ENVIRONMENT` **must** match the keys.

| Variable | Default | Meaning |
| --- | --- | --- |
| `VIPPS_CLIENT_ID` | *(empty)* | Sales unit client id |
| `VIPPS_CLIENT_SECRET` | *(empty)* | Sales unit client secret |
| `VIPPS_SUBSCRIPTION_KEY` | *(empty)* | `Ocp-Apim-Subscription-Key` |
| `VIPPS_MERCHANT_SERIAL_NUMBER` | *(empty)* | Sales unit MSN |
| `VIPPS_ENVIRONMENT` | `test` | `test` (apitest.vipps.no) or `production` (api.vipps.no) |
| `VIPPS_TIMEOUT` | `15` | Whole-request deadline, seconds. **Mandatory** — see below |
| `VIPPS_CONNECT_TIMEOUT` | `5` | TCP/TLS handshake deadline, seconds. **Mandatory** — see below |
| `VIPPS_SYSTEM_NAME` | app name | Sent as `Vipps-System-Name`; identifies your app in Vipps' logs |
| `VIPPS_SYSTEM_VERSION` | Laravel version | Sent as `Vipps-System-Version` |
| `VIPPS_WEBHOOK_SECRET` | *(empty)* | Signing secret from `vipps:webhooks register`. Empty means "webhooks not configured": the receiver answers **404** |
| `VIPPS_TOKEN_CACHE_STORE` | app default store | Cache store for [shared access tokens](#token-caching-across-workers) |
| `VIPPS_LOGIN_REDIRECT_URI` | *(empty)* | OAuth callback URL for [Vipps Login](#vipps-login-socialite) — must exactly match one registered in the portal |
| `VIPPS_LOGIN_SCOPES` | `openid name email phoneNumber` | Space-separated OIDC scopes for the Socialite driver |

> [!WARNING]
> **The timeouts are mandatory and enforced.** Resolving `Vipps` with a
> non-positive `VIPPS_TIMEOUT` or `VIPPS_CONNECT_TIMEOUT` throws a
> `LogicException` immediately. This is deliberate: Guzzle waits **forever**
> by default, and a payment call with no deadline doesn't fail — it silently
> wedges the queue worker that made it, which is far harder to notice than a
> boot-time exception naming the config key.

## Usage

`Vipps::` proxies to the container singleton, so the facade, injected
`Nesthus\Vipps\Vipps` instances and `app(Vipps::class)` all share one client
and one token cache. Each snippet below is the Laravel-flavoured minimum —
follow the deep links for the full flow, its DTOs and the rules that go with
it (most importantly: **mint and persist your own idempotency keys before the
request goes out** — the SDK requires one on every mutating call, and
[explains why](https://github.com/ekstremedia/vipps-php#why-this-sdk)).

### Recurring agreements ([full guide](https://github.com/ekstremedia/vipps-php#recurring-quick-start))

```php
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Laravel\Facades\Vipps;
use Nesthus\Vipps\Recurring\Interval;
use Nesthus\Vipps\Recurring\NewAgreement;
use Nesthus\Vipps\Recurring\Pricing;

$created = Vipps::recurring()->createAgreement(new NewAgreement(
    pricing: Pricing::legacy(Amount::fromMajor(49)),   // 49.00 NOK per charge
    interval: Interval::months(1),
    productName: 'Premium',
    merchantRedirectUrl: route('subscription.return'),
    merchantAgreementUrl: route('subscription.show'),  // where the user can manage/cancel
), $idempotencyKey);

// Persist $created->agreementId next to your key, THEN:
return redirect()->away($created->vippsConfirmationUrl);
```

The redirect back proves nothing about approval, and an active agreement moves
no money by itself — your scheduled job creates every charge. Both rules are
covered in the SDK guide.

### One-off payments ([full guide](https://github.com/ekstremedia/vipps-php#epayment-quick-start))

```php
use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Epayment\CreatePayment;
use Nesthus\Vipps\Laravel\Facades\Vipps;

$created = Vipps::epayment()->createPayment(new CreatePayment(
    amount: Amount::fromMajor(249, 50),    // 249.50 NOK — integer minor units, no floats
    reference: 'order-2026-000123',        // your permanent id: 8–64 chars of [a-zA-Z0-9-]
    returnUrl: route('checkout.return'),
), $idempotencyKey);

return redirect()->away($created->redirectUrl);   // null only for flows without a browser hop
```

### Login and webhook management

`Vipps::login()` exposes the raw OIDC module and `Vipps::webhooks()` the
subscription-management API, but in a Laravel app you rarely call either
directly — the [Socialite driver](#vipps-login-socialite) and the
[`vipps:webhooks` command](#webhooks) wrap them. `Vipps::tokens()` and
`Vipps::config()` complete the facade.

Everything the SDK throws implements the `Nesthus\Vipps\Exceptions\VippsException`
marker interface — see [Errors](https://github.com/ekstremedia/vipps-php#errors).

## Webhooks

### 1. Expose the receiver

One line, in a **stateless** routes file — server-to-server calls need no
session and must not hit CSRF:

```php
// routes/api.php
Route::vippsWebhooks('/vipps/webhooks');
```

Note that a fresh Laravel 11/12 app has **no `routes/api.php`** until you run
`php artisan install:api`. If you'd rather not install the API scaffolding,
any group without session/CSRF middleware works the same:

```php
// bootstrap/app.php — or any service provider's boot()
Route::middleware('api')->group(function () {
    Route::vippsWebhooks('/vipps/webhooks');
});
```

Call the macro **once** per app: it always registers the fixed route name
`vipps.webhooks` (which is how `vipps:webhooks register` finds your callback
URL), so a second call means two routes with the same name — `php artisan
route:cache` then fails with a duplicate-name error.

The macro registers the URI for **every** HTTP method behind two pieces of
middleware:

- **`throttle:vipps-webhooks`** — a rate limiter the package registers as
  120 requests/min per IP, so unauthenticated probes can't buy unlimited
  body-read + HMAC work. Override it by registering your own limiter under
  the name `vipps-webhooks` (in your app service provider's `register()`,
  which runs before the package's `boot()`) — the package only registers its
  default when the name is still free.
- **Signature verification** — forged or tampered deliveries are answered
  401 (with only a stable, leak-free reason slug logged) before any of your
  code runs. Any method other than POST answers **404**, and while
  `VIPPS_WEBHOOK_SECRET` is empty every request answers **404** — the same
  `NotFoundHttpException` a missing route produces, so scanners probing a
  freshly certified hostname get no confirmation the endpoint exists (the
  route registers every method precisely so a POST-only 405/`Allow` response
  can't leak it either).

The middleware is also available standalone under the alias
`vipps.webhook-signature` if you'd rather define the route yourself.

### 2. Register it with Vipps

```bash
php artisan vipps:webhooks register
```

With no options it registers the app's own `vipps.webhooks` route for the
ten `recurring.*` event types; `--url=` and repeatable `--events=` override
both. `vipps:webhooks list` and `vipps:webhooks delete --id=…` manage existing
subscriptions; `delete` asks for confirmation and exits non-zero when it is
declined (in scripts, pass `--force` to skip the prompt).

> [!WARNING]
> **The signing secret is shown exactly once**, in the command's output —
> Vipps never re-reveals it (listing returns id/url/events only), and the
> command deliberately never logs or stores it. Copy it into your `.env` as
> `VIPPS_WEBHOOK_SECRET` immediately; if you scroll past it, the only recovery
> is delete + re-register.

### 3. Listen

Every signature-verified delivery fires `VippsWebhookReceived` — including
event types this package has never heard of, because Vipps adds types without
notice and an unknown type must degrade to "generic event fired", never to a
dropped delivery. When the type is recognised, a typed companion event fires
as well, so you can subscribe at whichever granularity fits:

| `eventType` | Event class (`Nesthus\Vipps\Laravel\Events\…`) |
| --- | --- |
| *every delivery* | `VippsWebhookReceived` |
| `recurring.agreement-activated.v1` | `AgreementActivated` |
| `recurring.agreement-rejected.v1` | `AgreementRejected` |
| `recurring.agreement-stopped.v1` | `AgreementStopped` |
| `recurring.agreement-expired.v1` | `AgreementExpired` |
| `recurring.charge-reserved.v1` | `ChargeReserved` |
| `recurring.charge-captured.v1` | `ChargeCaptured` |
| `recurring.charge-canceled.v1` | `ChargeCanceled` |
| `recurring.charge-failed.v1` | `ChargeFailed` |
| `recurring.charge-creation-failed.v1` | `ChargeCreationFailed` |
| `recurring.charge-refunded.v1` | `ChargeRefunded` |

The controller answers 204 immediately and does nothing else — persistence,
dedupe and business logic are your listeners' job, and two rules keep them
honest:

- **Queue your listeners** (`ShouldQueue`). Vipps expects an answer within
  10 seconds and treats a timeout as a failure to retry, so anything slow in
  a synchronous listener turns one delivery into many.
- **Persist and dedupe before acting.** Vipps retries with exponential
  backoff for days, and every retry lands on your endpoint again and fires
  the same events again — store the payload's `eventId` and skip deliveries
  you have already processed.

```php
// app/Listeners/RecordChargeCollected.php — auto-discovered by Laravel
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nesthus\Vipps\Laravel\Events\ChargeCaptured;

final class RecordChargeCollected implements ShouldQueue
{
    public function handle(ChargeCaptured $event): void
    {
        $eventId = $event->payload['eventId'] ?? null;

        // Dedupe on the event id BEFORE acting — retries of the same
        // delivery fire this listener again.
        if ($eventId === null || $this->alreadyProcessed($eventId)) {
            return;
        }

        // Persist the delivery, then act on $event->payload['agreementId'],
        // $event->payload['chargeId'], …
    }
}
```

## Vipps Login (Socialite)

The `vipps` Socialite driver is registered automatically and reads its
credentials from `config/vipps.php` — no `config/services.php` entry needed.
Set `VIPPS_LOGIN_REDIRECT_URI` (it must **exactly** match a redirect URI
registered in the portal), and note that the sales unit must have **Login
activated** in the merchant portal before the authorize endpoint will accept
it.

```php
// routes/web.php — the driver keeps OAuth state and the PKCE verifier in the
// session, so these routes need the web middleware group.
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

Route::get('/auth/vipps/redirect', fn () => Socialite::driver('vipps')->redirect());

Route::get('/auth/vipps/callback', function () {
    $vippsUser = Socialite::driver('vipps')->user();

    $user = User::updateOrCreate(
        ['vipps_sub' => $vippsUser->getId()],   // OIDC `sub` — the stable identity; email can change
        [
            'name' => $vippsUser->getName(),
            'email' => $vippsUser->getEmail(),
        ],
    );

    Auth::login($user, remember: true);

    return redirect()->intended('/');
});
```

The requested scopes come from `VIPPS_LOGIN_SCOPES` (space-separated OIDC
scopes, default `openid name email phoneNumber`). Only `sub` is guaranteed;
which other claims arrive follows what the user actually granted — the phone
number, when present, is on `$vippsUser->phone_number`.

Two Vipps-specific deviations from Socialite's defaults are handled for you:
client authentication is HTTP Basic (`client_secret_basic`, which Vipps sales
units default to — a secret in the form body fails the token exchange with an
opaque 401), and PKCE is always on.

Two boundaries to know about: `SocialiteManager` builds each driver once and
caches it together with the request captured at build time, so under Octane
(or any long-lived worker) call `Socialite::forgetDrivers()` per request —
e.g. from an Octane `RequestReceived` listener — or the driver keeps serving
the boot-time request. And because the driver deliberately enforces PKCE plus
session-backed `state`, `stateless()` and session-less API flows are
unsupported — these routes must stay in the `web` middleware group.

## Token caching across workers

Merchant access tokens are fetched and refreshed automatically; you never
handle them. Unlike the bare SDK (whose default cache is per-process), the
bridge always stores tokens in a Laravel cache store, so php-fpm workers,
queue workers and Octane all share one token instead of each minting its own.

By default that is the app's default cache store; point
`VIPPS_TOKEN_CACHE_STORE` at another store name when the default isn't shared
by every process that talks to Vipps (for example, a per-host `file` store
behind multiple servers — use `redis`).

If Vipps ever answers 401 on a token that should have been valid (revoked
keys, clock trouble), `Vipps::tokens()->forget()` drops the cached token so
the next call fetches fresh.

## Testing your app

**Webhook-driven code:** don't simulate deliveries — construct the events
directly (their whole state is the payload array) and fake the dispatcher to
assert wiring:

```php
use Illuminate\Support\Facades\Event;
use Nesthus\Vipps\Laravel\Events\ChargeCaptured;

// Unit-test a listener by handing it the event:
(new RecordChargeCollected())->handle(new ChargeCaptured([
    'eventType' => 'recurring.charge-captured.v1',
    'eventId' => 'evt-42',
    'agreementId' => 'agr-123',
]));

// Or fake the dispatcher and assert your code fires/handles what it should:
Event::fake([ChargeCaptured::class]);
// … exercise your code …
Event::assertDispatched(ChargeCaptured::class);
```

**Outbound calls:** `Vipps` and its API modules are `final`, so they cannot be
mocked — and shouldn't be. Swap the container binding for a *real* `Vipps`
wired to a fake PSR-18 client instead, so the SDK's actual request/response
mapping still runs under test. The SDK ships a ~60-line queue-and-record fake
([`tests/Support/FakeHttpClient.php`](https://github.com/ekstremedia/vipps-php/blob/main/tests/Support/FakeHttpClient.php))
you can copy into your suite:

```php
use GuzzleHttp\Psr7\HttpFactory;
use Nesthus\Vipps\Vipps;
use Nesthus\Vipps\VippsConfig;

$fakeHttp = new FakeHttpClient();
$factory = new HttpFactory();

$this->app->instance(Vipps::class, new Vipps(
    new VippsConfig('client-id', 'client-secret', 'subscription-key', '123456'),
    $fakeHttp,
    $factory,
    $factory,
));

$fakeHttp->queueJson(201, ['agreementId' => 'agr-1', 'vippsConfirmationUrl' => 'https://…']);

// … exercise your code, then assert on $fakeHttp->lastRequest() —
// a full PSR-7 request: method, URI, Idempotency-Key header, body.
```

For end-to-end testing against real infrastructure, `VIPPS_ENVIRONMENT=test`
points at Vipps' **apitest** sandbox, with its own merchant keys and test
users.

## Development

No local PHP needed — everything runs in throwaway containers:

```bash
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app composer:2 install
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/pest
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/pint --test src tests
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app php:8.4-cli php vendor/bin/phpstan analyse --no-progress
```

Tests are [Testbench](https://packages.tools/testbench)-based: the suite boots
the real service provider with a fake configured sales unit and exercises
actual container wiring, route registration and middleware — no HTTP leaves
the machine.

## Versioning & license

This is a 0.x release: the public API may still move between minor versions —
pin accordingly and read [CHANGELOG.md](CHANGELOG.md) before upgrading. Once
the surface has survived real-world use, 1.0 freezes it under semantic
versioning.

MIT — see [LICENSE](LICENSE).

Built on [nesthus/vipps-php](https://github.com/ekstremedia/vipps-php), the
framework-agnostic SDK this package wraps — its README documents the API
modules themselves.
