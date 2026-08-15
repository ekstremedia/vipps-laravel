<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User;
use Nesthus\Vipps\Environment;
use Nesthus\Vipps\Laravel\Socialite\VippsSocialiteProvider;
use Nesthus\Vipps\Laravel\VippsServiceProvider;

/**
 * Reads the provider's self-built Guzzle client (the one used when no client
 * is injected via setHttpClient) and returns the resolved value of one Guzzle
 * option. Reflection is unavoidable for getHttpClient() (it is protected);
 * from there Client::getConfig() is deliberately chosen over reflecting
 * AbstractProvider's $guzzle property: getConfig() is doc-deprecated since
 * Guzzle 7.4 but functional and public across the whole guzzle ^7 range
 * Socialite pins, and it asserts the REAL client's resolved options — the
 * $guzzle property would only prove the intermediate array and couple the
 * test to Socialite's internal field name instead of observable behavior.
 */
function vippsProviderClientOption(VippsSocialiteProvider $provider, string $option): mixed
{
    /** @var GuzzleClient $client */
    $client = (new ReflectionMethod($provider, 'getHttpClient'))->invoke($provider);

    return $client->getConfig($option);
}

/**
 * Testbench ignores package discovery, so Socialite's (deferrable) provider is
 * never registered by the base TestCase — and VippsServiceProvider then skips
 * its driver registration on purpose (Socialite-not-bound guard). Overriding
 * getPackageProviders() via a trait registers Socialite the same way a real
 * app's package discovery would, so these tests exercise the REAL boot-time
 * extend() path instead of hand-wiring the driver. A trait (not a TestCase
 * subclass) because Pest allows one base class per file and tests/Pest.php
 * already binds it.
 */
trait RegistersSocialiteForVippsDriver
{
    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class, VippsServiceProvider::class];
    }
}

uses(RegistersSocialiteForVippsDriver::class);

/**
 * The container's request carries no session in a unit-style test, but
 * redirect() needs one for state + the PKCE verifier. Attach an array store
 * explicitly rather than relying on the default session driver.
 */
function vippsTestSession(Application $app): Session
{
    /** @var \Illuminate\Session\SessionManager $manager */
    $manager = $app->make('session');
    $session = $manager->driver('array');

    /** @var Request $request */
    $request = $app->make('request');
    $request->setLaravelSession($session);

    return $session;
}

it('resolves the vipps driver through the package service provider', function (): void {
    expect(Socialite::driver('vipps'))->toBeInstanceOf(VippsSocialiteProvider::class);
});

it('redirects to the test-host authorize endpoint with the OIDC query', function (): void {
    $session = vippsTestSession($this->app);

    $url = Socialite::driver('vipps')->redirect()->getTargetUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://apitest.vipps.no/access-management-1.0/access/oauth2/auth?')
        ->and($query['client_id'])->toBe('test-client-id')
        ->and($query['redirect_uri'])->toBe('https://example.test/auth/vipps/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('openid name email phoneNumber')
        ->and($query['state'])->toBe($session->get('state'))
        ->and($session->get('state'))->toBeString()->not->toBe('');
});

it('derives the PKCE challenge from the session-stored verifier', function (): void {
    $session = vippsTestSession($this->app);

    $url = Socialite::driver('vipps')->redirect()->getTargetUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $verifier = $session->get('code_verifier');
    expect($verifier)->toBeString()->not->toBe('');

    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');

    expect($query['code_challenge'])->toBe($expectedChallenge)
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('targets the production host when the environment is production', function (): void {
    config()->set('vipps.environment', 'production');
    vippsTestSession($this->app);

    $url = Socialite::driver('vipps')->redirect()->getTargetUrl();

    expect($url)->toStartWith('https://api.vipps.no/access-management-1.0/access/oauth2/auth?');
});

it('requests the configured scopes, space-separated', function (): void {
    config()->set('vipps.login.scopes', 'openid email');
    vippsTestSession($this->app);

    $url = Socialite::driver('vipps')->redirect()->getTargetUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid email');
});

it('falls back to the default scopes when the configured value is blank', function (): void {
    config()->set('vipps.login.scopes', '  ');
    vippsTestSession($this->app);

    $url = Socialite::driver('vipps')->redirect()->getTargetUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid name email phoneNumber');
});

it('exchanges the code with HTTP Basic auth and maps the userinfo claims', function (): void {
    $state = str_repeat('s', 40);

    // Simulate the callback request Vipps redirects the browser to, with the
    // state and PKCE verifier a prior redirect() would have left in session.
    $request = Request::create('https://example.test/auth/vipps/callback', 'GET', [
        'code' => 'the-auth-code',
        'state' => $state,
    ]);
    $this->app->instance('request', $request);

    $session = vippsTestSession($this->app);
    $session->put('state', $state);
    $session->put('code_verifier', 'the-pkce-verifier');

    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'the-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'openid name email phoneNumber',
        ])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'sub' => 'c06c4afe-d9e1-4c5d-939a-177d752a0944',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone_number' => '4712345678',
        ])),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $user = Socialite::driver('vipps')
        ->setHttpClient(new GuzzleClient(['handler' => $stack]))
        ->user();

    expect($history)->toHaveCount(2);

    /** @var \Psr\Http\Message\RequestInterface $tokenRequest */
    $tokenRequest = $history[0]['request'];

    expect($tokenRequest->getMethod())->toBe('POST')
        ->and((string) $tokenRequest->getUri())->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/token')
        ->and($tokenRequest->getHeaderLine('Authorization'))
        ->toBe('Basic ' . base64_encode('test-client-id:test-client-secret'));

    parse_str((string) $tokenRequest->getBody(), $form);

    expect($form)->not->toHaveKey('client_secret')
        ->and($form['grant_type'])->toBe('authorization_code')
        ->and($form['code'])->toBe('the-auth-code')
        ->and($form['redirect_uri'])->toBe('https://example.test/auth/vipps/callback')
        ->and($form['code_verifier'])->toBe('the-pkce-verifier');

    /** @var \Psr\Http\Message\RequestInterface $userinfoRequest */
    $userinfoRequest = $history[1]['request'];

    expect($userinfoRequest->getMethod())->toBe('GET')
        ->and((string) $userinfoRequest->getUri())->toBe('https://apitest.vipps.no/vipps-userinfo-api/userinfo')
        ->and($userinfoRequest->getHeaderLine('Authorization'))->toBe('Bearer the-access-token');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getId())->toBe('c06c4afe-d9e1-4c5d-939a-177d752a0944')
        ->and($user->getName())->toBe('Ada Lovelace')
        ->and($user->getEmail())->toBe('ada@example.test')
        ->and($user->attributes['phone_number'])->toBe('4712345678')
        ->and($user->getNickname())->toBeNull()
        ->and($user->getAvatar())->toBeNull()
        ->and($user->token)->toBe('the-access-token');
});

it('refreshes a token with HTTP Basic auth and no client_secret in the body', function (): void {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'the-new-access-token',
            'refresh_token' => 'the-new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid',
        ])),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $token = Socialite::driver('vipps')
        ->setHttpClient(new GuzzleClient(['handler' => $stack]))
        ->refreshToken('the-old-refresh-token');

    /** @var \Psr\Http\Message\RequestInterface $refreshRequest */
    $refreshRequest = $history[0]['request'];

    expect((string) $refreshRequest->getUri())->toBe('https://apitest.vipps.no/access-management-1.0/access/oauth2/token')
        ->and($refreshRequest->getHeaderLine('Authorization'))
        ->toBe('Basic ' . base64_encode('test-client-id:test-client-secret'));

    parse_str((string) $refreshRequest->getBody(), $form);

    expect($form)->not->toHaveKey('client_secret')
        ->and($form['grant_type'])->toBe('refresh_token')
        ->and($form['refresh_token'])->toBe('the-old-refresh-token')
        ->and($token->token)->toBe('the-new-access-token');
});

it('builds its own HTTP client with timeouts matching the config defaults', function (): void {
    // Resolved through the REAL service-provider path, which threads the
    // configured vipps.timeout / vipps.connect_timeout values through — so
    // with untouched config this pins the config/vipps.php defaults
    // (VIPPS_TIMEOUT=15 / VIPPS_CONNECT_TIMEOUT=5). Without them,
    // AbstractProvider::getHttpClient() builds `new Client([])`, which waits
    // forever and can wedge a worker on a hung token exchange or userinfo
    // call — the exact failure the service provider refuses to allow for the
    // SDK transport.
    $provider = Socialite::driver('vipps');

    expect($provider)->toBeInstanceOf(VippsSocialiteProvider::class);

    expect(vippsProviderClientOption($provider, RequestOptions::TIMEOUT))->toBe(15)
        ->and(vippsProviderClientOption($provider, RequestOptions::CONNECT_TIMEOUT))->toBe(5);
});

it('threads custom vipps.timeout and connect_timeout config through the service-provider path', function (): void {
    // The regression this pins: registerSocialiteDriver() used to omit the
    // timeout arguments, so apps that tuned VIPPS_TIMEOUT still got make()'s
    // defaults on the login driver. Custom values must reach the driver's own
    // Guzzle client via the real extend() closure, not only via direct make().
    config()->set('vipps.timeout', 7);
    config()->set('vipps.connect_timeout', 3);

    $provider = Socialite::driver('vipps');

    expect(vippsProviderClientOption($provider, RequestOptions::TIMEOUT))->toBe(7)
        ->and(vippsProviderClientOption($provider, RequestOptions::CONNECT_TIMEOUT))->toBe(3);
});

it('clamps zero or missing timeout config to a positive floor instead of an unlimited client', function (): void {
    // Zero/null would otherwise reach Guzzle as "no deadline" — the one value
    // the whole package exists to forbid. The provider clamps to 1 second.
    config()->set('vipps.timeout', 0);
    config()->set('vipps.connect_timeout', null);

    $provider = Socialite::driver('vipps');

    expect(vippsProviderClientOption($provider, RequestOptions::TIMEOUT))->toBe(1)
        ->and(vippsProviderClientOption($provider, RequestOptions::CONNECT_TIMEOUT))->toBe(1);
});

it('passes explicit timeout arguments through to its Guzzle client', function (): void {
    $provider = VippsSocialiteProvider::make(
        request: Request::create('https://example.test'),
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
        redirectUrl: 'https://example.test/auth/vipps/callback',
        environment: Environment::Test,
        scopes: 'openid',
        subscriptionKey: 'test-subscription-key',
        merchantSerialNumber: '123456',
        timeout: 3,
        connectTimeout: 2,
    );

    expect(vippsProviderClientOption($provider, RequestOptions::TIMEOUT))->toBe(3)
        ->and(vippsProviderClientOption($provider, RequestOptions::CONNECT_TIMEOUT))->toBe(2);
});

it('keeps an injected HTTP client untouched by the timeout defaults', function (): void {
    // setHttpClient() is the escape hatch every token-exchange test uses; the
    // timeout wiring must apply only to the client the provider builds itself.
    $injected = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]);

    $provider = Socialite::driver('vipps')->setHttpClient($injected);

    $client = (new ReflectionMethod($provider, 'getHttpClient'))->invoke($provider);

    expect($client)->toBe($injected);
});
