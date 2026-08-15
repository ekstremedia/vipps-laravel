<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Laravel\Tests\Support;

/**
 * Signs a webhook delivery exactly the way Vipps (Azure APIM) does, mirroring
 * the vector construction in the SDK's own SignatureValidatorTest — so the
 * feature tests exercise the REAL algorithm end to end through the HTTP
 * layer instead of stubbing the validator.
 *
 * Defaults match the package TestCase (secret) and Laravel's test client
 * (host `localhost`, plain path) so a test that doesn't care about the
 * signing parts can just call `WebhookSigner::headers($body)`.
 */
final class WebhookSigner
{
    private function __construct() {}

    /**
     * @return array<string, string> the three signature headers a real delivery carries
     */
    public static function headers(
        string $body,
        string $secret = 'test-webhook-secret',
        string $method = 'POST',
        string $pathAndQuery = '/hooks/vipps',
        string $host = 'localhost',
        ?string $date = null,
    ): array {
        // RFC 1123 HTTP-date, freshly minted: the validator enforces a ±300s
        // skew window, so "now" keeps every test delivery within it without
        // needing a frozen clock.
        $date ??= gmdate('D, d M Y H:i:s') . ' GMT';

        $contentHash = base64_encode(hash('sha256', $body, true));
        $signedString = strtoupper($method) . "\n" . $pathAndQuery . "\n" . $date . ';' . $host . ';' . $contentHash;
        $signature = base64_encode(hash_hmac('sha256', $signedString, $secret, true));

        return [
            'x-ms-date' => $date,
            'x-ms-content-sha256' => $contentHash,
            'Authorization' => 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $signature,
        ];
    }
}
