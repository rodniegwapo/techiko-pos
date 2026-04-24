<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class PayMongoTestController extends Controller
{
    public function index(): Response
    {
        $public = config('services.paymongo.public');

        return Inertia::render('PayMongo/Test', [
            'publishableKey' => $public,
            'publishableKeyMasked' => $public
                ? substr((string) $public, 0, 12).'…'
                : null,
        ]);
    }

    public function storePaymentIntent(Request $request): JsonResponse
    {
        $secret = config('services.paymongo.secret');
        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'PAYMONGO_SECRET_KEY is not configured.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:2000', 'max:999999999'],
        ]);

        $baseUrl = config('services.paymongo.base_url');
        $url = $baseUrl.'/payment_intents';

        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => $validated['amount'],
                    'currency' => 'PHP',
                    'payment_method_allowed' => ['card'],
                    'description' => 'Techiko POS PayMongo test',
                ],
            ],
        ];

        $response = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($secret, '')
            ->post($url, $payload);

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'PayMongo request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        $json = $response->json();
        $data = $json['data'] ?? null;
        $attrs = is_array($data) ? ($data['attributes'] ?? []) : [];

        return response()->json([
            'success' => true,
            'payment_intent_id' => is_array($data) ? ($data['id'] ?? null) : null,
            'status' => $attrs['status'] ?? null,
            'client_key' => $attrs['client_key'] ?? null,
            'raw' => $json,
        ]);
    }
}
