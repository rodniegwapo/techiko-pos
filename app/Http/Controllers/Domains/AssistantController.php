<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionRegistry;
use App\Services\Ai\Actions\PendingActions;
use App\Services\Ai\AppAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The AI assistant tab of the chat drawer: answers how-to questions from the guides the user's
 * role allows and prepares changes, which run only when the user confirms them here. Messages
 * are limited per user per day to keep API costs predictable.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AppAssistant $assistant,
        private readonly PendingActions $pending,
        private readonly ActionRegistry $actions,
    ) {}

    public function ask(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.question' => ['required', 'string', 'max:1000'],
            'history.*.answer' => ['required', 'string', 'max:4000'],
            'page' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9 .\-_]+$/'],
            'language' => ['nullable', Rule::in(AppAssistant::LANGUAGES)],
            'location_id' => ['nullable', 'integer'],
        ]);

        if (! $this->assistant->isConfigured()) {
            return response()->json(['message' => 'The AI assistant is not set up yet. Ask your administrator to add an AI API key.'], 503);
        }

        $user = $request->user();
        $limit = (int) config('services.app_assistant.daily_limit', 50);
        $counter = 'assistant-messages:'.$domain->name_slug.':'.$user->id.':'.today()->toDateString();
        $sent = (int) Cache::get($counter, 0);
        if ($sent >= $limit) {
            return response()->json(['message' => "You've reached today's limit of {$limit} messages. The assistant opens again tomorrow; the team chat still works."], 429);
        }
        Cache::put($counter, $sent + 1, now()->endOfDay());

        try {
            $reply = $this->assistant->reply(
                $validated['message'],
                $validated['history'] ?? [],
                $user,
                $domain,
                $this->location($request, $domain),
                $validated['page'] ?? null,
                $validated['language'] ?? 'en',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            ...$reply,
            'remaining' => max(0, $limit - $sent - 1),
        ]);
    }

    /** Runs a change the assistant prepared, once, for the user it was prepared for, if their role still allows it. */
    public function confirm(Request $request, Domain $domain, string $token): JsonResponse
    {
        $user = $request->user();
        $pending = $this->pending->get($token);

        if (! $pending || $pending['domain'] !== $domain->name_slug) {
            return response()->json(['message' => 'This request expired or was already used. Ask the assistant again.'], 404);
        }
        if ((int) $pending['user_id'] !== (int) $user->id) {
            return response()->json(['message' => 'Only the person who asked can confirm this.'], 403);
        }

        $action = $this->actions->find($pending['action']);
        if (! $action || ! $this->actions->allows($action, $user, $domain)) {
            return response()->json(['message' => 'Your role is not allowed to make this change.'], 403);
        }

        // Taken out before it runs, so a double click can't run it twice.
        if (! $this->pending->pull($token)) {
            return response()->json(['message' => 'This request expired or was already used. Ask the assistant again.'], 404);
        }

        $location = $pending['location_id']
            ? InventoryLocation::query()->forDomain($domain->name_slug)->find($pending['location_id'])
            : null;
        $result = $action->run($pending['input'], new ActionContext($user, $domain, $location));

        Log::info('assistant.action', [
            'action' => $pending['action'],
            'ok' => $result->ok,
            'user_id' => $user->id,
            'domain' => $domain->name_slug,
            'input' => $pending['input'],
            'message' => $result->message,
        ]);

        return $result->ok
            ? response()->json(['message' => $result->message])
            : response()->json(['message' => $result->message, 'errors' => $result->errors], 422);
    }

    public function cancel(Request $request, Domain $domain, string $token): JsonResponse
    {
        $pending = $this->pending->get($token);
        if ($pending && $pending['domain'] === $domain->name_slug && (int) $pending['user_id'] === (int) $request->user()->id) {
            $this->pending->pull($token);
        }

        return response()->json(['message' => 'Cancelled. Nothing was changed.']);
    }

    /** The store the user works in, worked out the way the pages do (restricted users keep their own store). */
    private function location(Request $request, Domain $domain): ?InventoryLocation
    {
        $user = $request->user();
        $locationId = $user->hasLocationRestriction()
            ? $user->getEffectiveLocationId($request->input('location_id'))
            : $request->input('location_id');

        return Helpers::getActiveLocation($domain, $locationId);
    }
}
