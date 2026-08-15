<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Nesthus\Vipps\Environment;

/**
 * Socialite driver for Vipps Login — a standard OIDC authorization-code flow
 * against the access-management endpoints on the host selected by
 * {@see Environment} (test keys only work against the test host, so the
 * environment MUST match the credentials).
 *
 * Two Vipps-specific deviations from Socialite's defaults, both deliberate:
 *
 * - Client authentication is HTTP Basic (`client_secret_basic`), never the
 *   secret in the form body. Vipps sales units default to
 *   `client_secret_basic`, and RFC 6749 §2.3.1 forbids using both mechanisms
 *   at once — sending the secret in the body as well makes the token
 *   exchange fail with an opaque 401.
 *
 * - PKCE is always on. Socialite's built-in support (session-stored
 *   verifier, S256 challenge) does all the work; the sales unit must accept
 *   PKCE on the authorization request, which Vipps Login supports. State is
 *   still enforced by Socialite on top of it.
 */
final class VippsSocialiteProvider extends AbstractProvider
{
    /**
     * Mirrors the config/vipps.php default. Duplicated here so an explicitly
     * emptied VIPPS_LOGIN_SCOPES cannot produce an authorize URL without even
     * `openid` — Vipps rejects that with an error the user sees, not the
     * developer.
     */
    private const DEFAULT_SCOPES = 'openid name email phoneNumber';

    /**
     * OIDC scopes are space-separated per spec; Socialite's default is ','.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * Opt in to Socialite's built-in PKCE handling (see class docblock).
     *
     * @var bool
     */
    protected $usesPKCE = true;

    /**
     * Private on purpose: Socialite's generic buildProvider() cannot supply
     * the environment, so every instance must come through {@see make()},
     * which is what VippsServiceProvider's extend() closure calls.
     *
     * The fifth parent argument is load-bearing: AbstractProvider's
     * getHttpClient() does `new Client($this->guzzle)`, and a bare
     * `new Client([])` has NO timeout — a wedged Vipps endpoint would then
     * block the PHP worker forever inside the token exchange or userinfo
     * call. Passing the timeouts here upholds the same invariant the service
     * provider enforces for the SDK transport (it refuses to boot without
     * them), so the Socialite path can never be the one place that skips it.
     */
    private function __construct(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        private readonly Environment $environment,
        int $timeout,
        int $connectTimeout,
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, [
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::CONNECT_TIMEOUT => $connectTimeout,
        ]);
    }

    /**
     * Named constructor matching VippsServiceProvider::registerSocialiteDriver().
     *
     * $subscriptionKey and $merchantSerialNumber are accepted but deliberately
     * NOT sent: the OIDC endpoints (authorize, token, userinfo) authenticate
     * with client credentials or the user's own bearer token and need no
     * subscription key per the Vipps Login docs. They stay in the signature so
     * that sending them later (e.g. Vipps-System telemetry headers) is a
     * non-breaking change to the provider wiring.
     *
     * $timeout and $connectTimeout bound the driver's own Guzzle client (token
     * exchange, refresh, userinfo). They are explicit parameters — not a
     * config() read inside this method — because this class stays
     * framework-decoupled and container-agnostic; the caller owns config
     * resolution. The service provider threads the configured vipps.timeout /
     * vipps.connect_timeout values through (clamped to a positive floor), so
     * the defaults here only cover direct make() callers — they still MUST
     * mirror config/vipps.php (VIPPS_TIMEOUT=15 / VIPPS_CONNECT_TIMEOUT=5),
     * or default-drift would silently diverge the two paths.
     *
     * @param string $scopes space-separated OIDC scopes; blank falls back to
     *                       {@see self::DEFAULT_SCOPES} (see that constant for why)
     * @param int $timeout total request timeout in seconds for the driver's HTTP client
     * @param int $connectTimeout TCP connect timeout in seconds for the driver's HTTP client
     */
    public static function make(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        Environment $environment,
        string $scopes,
        string $subscriptionKey,
        string $merchantSerialNumber,
        int $timeout = 15,
        int $connectTimeout = 5,
    ): self {
        $scopeList = preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY);

        if ($scopeList === false || $scopeList === []) {
            $scopeList = explode(' ', self::DEFAULT_SCOPES);
        }

        $provider = new self($request, $clientId, $clientSecret, $redirectUrl, $environment, $timeout, $connectTimeout);
        $provider->setScopes($scopeList);

        return $provider;
    }

    /**
     * @param string $state
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(
            $this->environment->baseUrl() . '/access-management-1.0/access/oauth2/auth',
            $state,
        );
    }

    protected function getTokenUrl(): string
    {
        return $this->environment->baseUrl() . '/access-management-1.0/access/oauth2/token';
    }

    /**
     * The Basic Authorization header carries the client credentials for the
     * token exchange (see class docblock for why not the form body).
     *
     * @param string $code
     * @return array<string, string>
     */
    protected function getTokenHeaders($code): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => $this->basicAuthorization(),
        ];
    }

    /**
     * Socialite's default fields include client_secret; strip it because the
     * secret already travels in the Basic header. client_id stays — a bare
     * identifier in the body is identification, not a second authentication
     * mechanism, so RFC 6749 §2.3.1 permits it.
     *
     * @param string $code
     * @return array<string, string>
     */
    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);

        unset($fields['client_secret']);

        return $fields;
    }

    /**
     * Same client_secret_basic rule as the code exchange — Socialite's parent
     * implementation would put the secret in the body and fail against Vipps.
     *
     * @param string $refreshToken
     * @return array<string, mixed>|null
     */
    protected function getRefreshTokenResponse($refreshToken): ?array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => $this->basicAuthorization(),
            ],
            RequestOptions::FORM_PARAMS => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
            ],
        ]);

        /** @var array<string, mixed>|null */
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * The userinfo endpoint is authorized by the USER's access token from the
     * code exchange — not merchant credentials, so no Basic header here.
     *
     * @param string $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(
            $this->environment->baseUrl() . '/vipps-userinfo-api/userinfo',
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ],
        );

        /** @var array<string, mixed> */
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * Only `sub` is guaranteed by OIDC; which other claims arrive follows the
     * scopes the user actually granted, hence the null coalescing on each.
     * Vipps has no username or profile picture, so nickname and avatar are
     * explicit nulls rather than absent keys — consumers can rely on the shape.
     *
     * @param array<string, mixed> $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => null,
            'phone_number' => $user['phone_number'] ?? null,
        ]);
    }

    /**
     * RFC 7617 Basic credentials from the sales unit's client id and secret.
     */
    private function basicAuthorization(): string
    {
        return 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
    }
}
