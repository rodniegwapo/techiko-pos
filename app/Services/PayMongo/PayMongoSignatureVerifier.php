<?php

namespace App\Services\PayMongo;

class PayMongoSignatureVerifier
{
    /**
     * Verifies Paymongo-Signature header (t, te=test, li=live).
     *
     * @see https://developers.paymongo.com/docs/creating-a-webhook
     */
    public function verify(?string $signatureHeader, string $rawBody, bool $livemode): bool
    {
        $secret = (string) config('paymongo.webhook_secret', '');
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $segment = trim($segment);
            $eqPos = strpos($segment, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = strtolower(substr($segment, 0, $eqPos));
            $value = substr($segment, $eqPos + 1);
            $parts[$key] = $value;
        }

        $t = $parts['t'] ?? '';
        $expectedDigest = $livemode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');

        if ($t === '' || $expectedDigest === '') {
            return false;
        }

        $signed = hash_hmac('sha256', $t.'.'.$rawBody, $secret);

        return hash_equals($expectedDigest, $signed);
    }
}
