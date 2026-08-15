<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Nesthus\Vipps\Auth\AccessToken;
use Nesthus\Vipps\Auth\Psr16TokenCache;
use Nesthus\Vipps\Vipps;

/*
 * Token-cache WIRING test. Reflection is acceptable here, and only here:
 * Vipps keeps its token cache in a private constructor property with no
 * accessor, and the only non-reflective way to observe it is a live token
 * call against Vipps — a real HTTP request a unit test must never make.
 */

/**
 * @return mixed the value of a private property, read via reflection
 */
function privateValueOf(object $object, string $property): mixed
{
    return (new ReflectionProperty($object, $property))->getValue($object);
}

it('wires a Psr16TokenCache backed by the configured store', function (): void {
    config()->set('vipps.token_cache_store', 'array');

    $tokenCache = privateValueOf(app(Vipps::class), 'tokenCache');

    expect($tokenCache)->toBeInstanceOf(Psr16TokenCache::class);

    // Follow the wiring one level deeper: the PSR-16 bridge must hold the
    // repository of the CONFIGURED store, not the app default — pointing
    // token storage at Redis via config is the feature under test.
    $repository = privateValueOf($tokenCache, 'cache');

    expect($repository)->toBeInstanceOf(Repository::class)
        ->and($repository->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('stores cached tokens in the configured Laravel store', function (): void {
    config()->set('vipps.token_cache_store', 'array');

    /** @var Psr16TokenCache $tokenCache */
    $tokenCache = privateValueOf(app(Vipps::class), 'tokenCache');

    // Observable behavior without HTTP: put a token through the SDK's cache
    // and read it back through Laravel's — same store, so it must be there.
    $tokenCache->put('client-credentials', new AccessToken(
        'test-token-value',
        new DateTimeImmutable('+1 hour'),
    ));

    // The prefix uses dots, not colons — ':' is a PSR-16 RESERVED key
    // character and strict stores reject it (fixed in the SDK's 0.1.x line).
    $stored = Cache::store('array')->get('nesthus-vipps.token.client-credentials');

    expect($stored)->toBeArray()
        ->and($stored['value'] ?? null)->toBe('test-token-value');
});
