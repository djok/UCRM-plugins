<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Webhook;

/**
 * Verifies Revolut webhook signatures.
 * payload_to_sign = "v1.{Revolut-Request-Timestamp}.{raw_body}"
 * expected        = "v1=" . hex( HMAC_SHA256(signing_secret, payload_to_sign) )
 * The Revolut-Signature header may contain several comma-separated values.
 */
final class SignatureVerifier
{
    public function __construct(private readonly int $toleranceSeconds = 300)
    {
    }

    public function isValid(
        string $rawBody,
        string $timestampHeader,
        string $signatureHeader,
        string $signingSecret,
        int $nowSeconds,
    ): bool {
        if ($rawBody === '' || $timestampHeader === '' || $signatureHeader === '' || $signingSecret === '') {
            return false;
        }

        // Timestamp header is in milliseconds.
        $eventSeconds = (int) (((float) $timestampHeader) / 1000.0);
        if (abs($nowSeconds - $eventSeconds) > $this->toleranceSeconds) {
            return false;
        }

        $payloadToSign = 'v1.' . $timestampHeader . '.' . $rawBody;
        $expected = 'v1=' . hash_hmac('sha256', $payloadToSign, $signingSecret);

        foreach (explode(',', $signatureHeader) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
