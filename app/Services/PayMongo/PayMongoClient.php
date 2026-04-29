<?php

namespace App\Services\PayMongo;

use Illuminate\Support\Facades\Http;

class PayMongoClient
{
    public function post(string $path, array $payload): array
    {
        $secret = config('paymongo.secret_key');
        if (! $secret) {
            throw new \RuntimeException('PAYMONGO_SECRET_KEY is not configured.');
        }

        $url = rtrim(config('paymongo.api_base'), '/').'/'.ltrim($path, '/');

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if ($response->failed()) {
            $response->throw();
        }

        return $response->json();
    }

    public function isConfigured(): bool
    {
        return (bool) config('paymongo.secret_key');
    }
}
