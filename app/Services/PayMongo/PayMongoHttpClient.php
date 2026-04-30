<?php

namespace App\Services\PayMongo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class PayMongoHttpClient
{
    private Client $http;

    public function __construct()
    {
        $secret = (string) config('paymongo.secret_key', '');

        if ($secret === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $encoded = base64_encode($secret.':');

        $this->http = new Client([
            'base_uri' => rtrim((string) config('paymongo.api_base', 'https://api.paymongo.com/v1'), '/').'/',
            'headers' => [
                'Authorization' => 'Basic '.$encoded,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 25,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function postWithStatus(string $endpoint, array $payload): array
    {
        try {
            $response = $this->http->post(ltrim($endpoint, '/'), [
                'json' => $payload,
            ]);
            $decoded = json_decode((string) $response->getBody(), true);

            return [
                'status_code' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
            ];
        } catch (GuzzleException $e) {
            throw new RuntimeException('PayMongo API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $payload): array
    {
        $out = $this->postWithStatus($endpoint, $payload);
        if ($out['status_code'] >= 400) {
            throw new RuntimeException(
                $this->formatApiErrorSummary($out['body']).' ('.$out['status_code'].')'
            );
        }

        return $out['body'];
    }

    /**
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function getWithStatus(string $endpoint): array
    {
        try {
            $response = $this->http->get(ltrim($endpoint, '/'));
            $decoded = json_decode((string) $response->getBody(), true);

            return [
                'status_code' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
            ];
        } catch (GuzzleException $e) {
            throw new RuntimeException('PayMongo API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $endpoint): array
    {
        $out = $this->getWithStatus($endpoint);
        if ($out['status_code'] >= 400) {
            throw new RuntimeException(
                $this->formatApiErrorSummary($out['body']).' ('.$out['status_code'].')'
            );
        }

        return $out['body'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function formatApiErrorSummary(array $body): string
    {
        $errors = data_get($body, 'errors');
        if (is_array($errors) && $errors !== []) {
            $detail = [];
            foreach ($errors as $e) {
                if (isset($e['detail']) && is_string($e['detail'])) {
                    $detail[] = $e['detail'];
                }
            }

            return implode('; ', array_unique($detail)) ?: 'PayMongo API error.';
        }

        return 'PayMongo API error.';
    }
}
