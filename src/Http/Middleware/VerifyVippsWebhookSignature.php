<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Nesthus\Vipps\Webhooks\SignatureValidator;
use Nesthus\Vipps\Webhooks\WebhookRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates the webhook receiver registered by `Route::vippsWebhooks(...)`
 * (also available standalone under the 'vipps.webhook-signature' alias).
 * The cryptography lives in the SDK's {@see SignatureValidator}; this class
 * only adapts the Laravel request to it and enforces the HTTP posture:
 *
 *   - 404 for any method other than POST, checked FIRST. Real deliveries
 *     are always POST; the macro registers the route with Route::any so a
 *     POST-only 405/Allow (or an OPTIONS 200) can never confirm the path
 *     exists to a method-probing scanner — every other verb gets the same
 *     404 an unregistered path would.
 *   - 404 (never 401/403) when `vipps.webhook_secret` is empty, checked
 *     before any signature work — an unconfigured endpoint should look like
 *     a missing route to scanners probing a freshly certified hostname.
 *     (Not a perfect disguise: the macro's 'vipps-webhooks' throttle sits in
 *     front of this middleware, so sustained probing can surface a 429 that
 *     a truly missing route would not send.)
 *   - 401 on any invalid signature, after logging a warning that carries
 *     ONLY the validator's stable reason slug. The secret, the received
 *     signature and the computed HMAC never reach a log line — the slugs
 *     are designed to be loggable verbatim without leaking signing
 *     material, and this class keeps that guarantee by never logging
 *     anything else.
 *   - Rejection happens before the controller runs, so a forged delivery
 *     never dispatches an event or touches consumer code.
 */
final class VerifyVippsWebhookSignature
{
    /**
     * Only the three headers that are part of the signed string — nothing
     * else from the request is ever signature material.
     */
    private const SIGNATURE_HEADERS = ['x-ms-date', 'x-ms-content-sha256', 'authorization'];

    /**
     * The validator is injected (not `new`ed) so an app can rebind it with a
     * custom clock or skew window without touching this class.
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
        private readonly SignatureValidator $validator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Non-POST first, before even the secret is read: Vipps only ever
        // POSTs, and answering anything but 404 to GET/HEAD/OPTIONS/… would
        // hand method-probing scanners the confirmation the posture above
        // exists to deny.
        if (! $request->isMethod('POST')) {
            throw new NotFoundHttpException();
        }

        $secret = $this->config->get('vipps.webhook_secret', '');

        // A non-string value is as unconfigured as an empty one — fail
        // towards "endpoint does not exist", never towards validating with
        // a coerced secret.
        if (! is_string($secret) || $secret === '') {
            // Before any signature work — not even reading the body — so the
            // unconfigured response is the same NotFoundHttpException a
            // missing route produces.
            throw new NotFoundHttpException();
        }

        $result = $this->validator->validate($this->webhookRequest($request), $secret);

        if (! $result->valid) {
            // The reason is a bare snake_case slug by the SDK's contract —
            // safe to log verbatim. Nothing else about the request goes in.
            $this->logger->warning('Vipps webhook signature check failed.', [
                'reason' => $result->reason,
            ]);

            throw new HttpException(401);
        }

        return $next($request);
    }

    /**
     * Adapts the Illuminate request to the SDK's framework-free shape. The
     * body is passed through as the EXACT bytes received — never decoded and
     * re-encoded, which could reorder keys or change whitespace and break
     * (or worse, falsely satisfy) the content hash. Likewise the raw
     * request URI and Host header are used as sent, because both are part
     * of the signed string.
     */
    private function webhookRequest(Request $request): WebhookRequest
    {
        $headers = [];
        foreach (self::SIGNATURE_HEADERS as $name) {
            $value = $request->headers->get($name);
            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return new WebhookRequest(
            method: $request->getMethod(),
            pathAndQuery: $request->getRequestUri(),
            host: $request->headers->get('host') ?? $request->getHost(),
            rawBody: $request->getContent(),
            headers: $headers,
        );
    }
}
