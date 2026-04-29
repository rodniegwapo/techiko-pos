<?php

namespace App\Services\PayMongo;

class PayMongoWebhookSignature
{
    /**
     * Verify Paymongo-Signature header per https://developers.paymongo.com/docs/creating-webhook
     */
    public function valid(string $rawBody, ?string $signatureHeader, bool $livemode): bool
    {
        $secret = config('paymongo.webhook_secret');
        if (! $secret || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            if (! str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }

        $t = $parts['t'] ?? '';
        if ($t === '') {
            return false;
        }

        $te = $parts['te'] ?? '';
        $li = $parts['li'] ?? '';
        $expectedHeaderSig = $livemode ? $li : $te;

        if ($expectedHeaderSig === '') {
            return false;
        }

        $signedPayload = $t.'.'.$rawBody;
        $computed = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedHeaderSig, $computed);
    }
}
