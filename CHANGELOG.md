# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(0.x: the public API may still move between minor versions).

## [0.1.0] - 2026-08-15

### Added

- **Service provider** — one lazy `Nesthus\Vipps\Vipps` singleton built from
  the publishable `config/vipps.php` (`vendor:publish --tag=vipps-config`),
  with transport deadlines **enforced at resolve time**: non-positive
  `vipps.timeout` / `vipps.connect_timeout` values are refused with a
  `LogicException`, because Guzzle waits forever by default and a payment
  call with no deadline can wedge a queue worker. An app with no Vipps
  credentials boots fine and only fails when something actually resolves
  Vipps. `Vipps-System-*` headers default to the app name and Laravel
  version, with the plugin identified as `nesthus/vipps-laravel`.
- **Shared token cache** — merchant access tokens live in a Laravel cache
  store via the SDK's `Psr16TokenCache` (store selected by
  `VIPPS_TOKEN_CACHE_STORE`, defaulting to the app's default store), so
  php-fpm workers, queue workers and Octane share one token instead of each
  minting its own.
- **`Vipps` facade** — `recurring()`, `epayment()`, `login()`, `webhooks()`,
  `tokens()` and `config()`, proxying to the container singleton (never a
  facade-private copy, so the token cache stays shared with injection users).
- **Webhook receiver** — the `Route::vippsWebhooks($uri)` macro registers
  the URI for **every** HTTP method (named `vipps.webhooks`) behind an
  overridable `vipps-webhooks` rate limiter (120/min per IP) and
  `VerifyVippsWebhookSignature`, which adapts the Laravel request to the
  SDK's validator without re-encoding the body (the signature covers exact
  bytes) and enforces the HTTP posture: **404** for any method other than
  POST (registering every method is what keeps a POST-only 405/`Allow` or
  OPTIONS response from confirming the endpoint exists), **404** while
  `vipps.webhook_secret` is empty — the same `NotFoundHttpException` a
  missing route produces, so scanners cannot confirm the endpoint exists —
  and **401** on invalid signatures, logging only the validator's stable,
  leak-free reason slug. The middleware is also available standalone under
  the `vipps.webhook-signature` alias.
- **Webhook events** — `VippsWebhookReceived` fires for every verified
  delivery (unknown and even non-JSON bodies degrade to the generic event,
  never to an error into Vipps' retry loop), plus typed companion events for
  the ten documented `recurring.*` types: `AgreementActivated`,
  `AgreementRejected`, `AgreementStopped`, `AgreementExpired`,
  `ChargeReserved`, `ChargeCaptured`, `ChargeCanceled`, `ChargeFailed`,
  `ChargeCreationFailed` and `ChargeRefunded`. (Pre-release drafts named a
  `ChargeCharged` event for `recurring.charge-charged.v1` — that type does
  not exist in the official catalogue; the real type is
  `recurring.charge-captured.v1` → `ChargeCaptured`.) The controller answers
  204 immediately; persistence, dedupe and business logic belong to (queued)
  listeners.
- **`vipps:webhooks` command** — `register` (defaults to the app's own
  `vipps.webhooks` route and the ten recurring event types; `--url=` and
  repeatable `--events=` override), `list`, and `delete --id=…` with
  confirmation (declining exits non-zero; `--force` skips the prompt for
  scripted runs). The signing secret is printed **exactly once** at
  registration and never logged or stored — Vipps never re-reveals it.
- **Socialite driver `vipps`** — Vipps Login as a standard OIDC
  authorization-code flow reading `config/vipps.php` (no
  `config/services.php` entry), with the two Vipps-specific deviations
  handled: client authentication over HTTP Basic (`client_secret_basic` —
  a secret in the form body fails the token exchange per RFC 6749 §2.3.1)
  and PKCE always on. Scopes come from `vipps.login.scopes`, the host from
  `vipps.environment`, and an explicitly emptied scope list falls back to
  `openid name email phoneNumber` rather than producing an authorize URL
  without `openid`.

### Known limitations (carried from nesthus/vipps-php 0.1.0)

- **No JWKS id_token verification.** The SDK's `TokenSet::idTokenClaims()`
  decodes the id token without checking its signature — safe only because
  the token arrives over TLS directly from the token endpoint in a
  confidential-client exchange. (The Socialite driver reads the user from
  the `userinfo` endpoint, not the id token.) Tokens from any other channel
  must be verified with a real JWT library.
- **Webhook secret encoding unconfirmed.** The SDK's `SignatureValidator`
  HMACs with the secret's raw bytes; whether the secret Vipps returns needs
  a base64 decode first is pending verification against the first real
  sandbox delivery. Getting it wrong shows up as a logged
  `signature_mismatch` (and a 401 to Vipps) on otherwise well-formed
  deliveries.

[0.1.0]: https://github.com/ekstremedia/vipps-laravel/releases/tag/v0.1.0
