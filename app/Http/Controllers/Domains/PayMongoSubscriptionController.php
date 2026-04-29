<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\PayMongo\PayMongoSubscriptionService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

class PayMongoSubscriptionController extends Controller
{
    public function store(Request $request, Domain $domain, PayMongoSubscriptionService $subscriptions)
    {
        $user = $request->user();
        if ($user->domain !== $domain->name_slug && ! $user->is_super_user) {
            abort(403);
        }

        if (! config('paymongo.secret_key')) {
            return response()->json(['message' => 'Billing is not configured.'], 503);
        }

        $domain->refresh();

        if ($domain->subscription_active) {
            return response()->json([
                'message' => 'Subscription already active.',
                'subscription_active' => true,
            ]);
        }

        try {
            $result = $subscriptions->startSubscription($domain, $user);
        } catch (RequestException $e) {
            return response()->json([
                'message' => 'PayMongo request failed.',
                'errors' => $e->response ? $e->response->json() : null,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $raw = $result['raw'];
        $clientKey = data_get($raw, 'data.attributes.latest_invoice.payment_intent.attributes.client_key');
        $nextActionUrl = data_get($raw, 'data.attributes.latest_invoice.payment_intent.attributes.next_action.redirect.url');

        return response()->json([
            'subscription_id' => $result['subscription_id'],
            'status' => $result['status'],
            'subscription_active' => $result['status'] === 'active',
            'payment' => array_filter([
                'client_key' => $clientKey,
                'next_action_redirect_url' => $nextActionUrl,
            ]),
        ]);
    }
}
