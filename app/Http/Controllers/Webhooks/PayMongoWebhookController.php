<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\PayMongo\PayMongoWebhookSignature;
use App\Services\PayMongo\PayMongoWebhookSubscriptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PayMongoWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PayMongoWebhookSignature $signature,
        PayMongoWebhookSubscriptionHandler $handler
    ): Response {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Invalid JSON', 400);
        }

        $livemode = (bool) data_get($payload, 'data.attributes.livemode', false);
        $header = $request->header('Paymongo-Signature');
        if (! $signature->valid($raw, $header, $livemode)) {
            return response('Invalid signature', 401);
        }

        $handler->handleEventPayload($payload);

        return response()->json(['received' => true]);
    }
}
