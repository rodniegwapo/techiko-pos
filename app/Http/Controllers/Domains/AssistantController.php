<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionRegistry;
use App\Services\Ai\Actions\PendingActions;
use App\Services\Ai\AiCreditService;
use App\Services\Ai\AppAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        private readonly AiCreditService $credits,
    ) {}

    public function ask(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'conversation_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9 .\-_]+$/'],
            'language' => ['nullable', Rule::in(AppAssistant::LANGUAGES)],
            'location_id' => ['nullable', 'integer'],
        ]);

        if (! $this->assistant->isConfigured()) {
            return response()->json(['message' => 'The AI assistant is not set up yet. Ask your administrator to add an AI API key.'], 503);
        }

        $user = $request->user();
        // Only the user's own chat in this organization can be continued.
        $conversation = isset($validated['conversation_id'])
            ? AssistantConversation::query()->ownedBy($user, $domain)->findOrFail($validated['conversation_id'])
            : null;

        // The organization's monthly AI credits (answers 402 when used up), then each user's daily limit.
        $this->credits->assertHasCredits($domain, 'assistant');

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
                $this->history($conversation),
                $user,
                $domain,
                $this->location($request, $domain),
                $validated['page'] ?? null,
                $validated['language'] ?? 'en',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $usage = $this->credits->record($domain, $user, 'assistant', $this->assistant->lastResponse());
        $saved = $this->save($conversation, $validated, $reply, $user, $domain, $usage);

        return response()->json([
            ...Arr::except($reply, ['tools_called']),
            'conversation_id' => $saved->conversation_id,
            'message_id' => $saved->id,
            'remaining' => max(0, $limit - $sent - 1),
            'credits_remaining' => $this->credits->remaining($domain),
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
        $this->settleSavedCard($pending, $token, $result->ok ? 'done' : 'failed', $result->message, $result->ok ? null : $result->errors);

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
            $this->settleSavedCard($pending, $token, 'cancelled', 'Cancelled. Nothing was changed.');
        }

        return response()->json(['message' => 'Cancelled. Nothing was changed.']);
    }

    /**
     * The chat's last few turns for the AI, with what became of their change cards.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function history(?AssistantConversation $conversation): array
    {
        if (! $conversation) {
            return [];
        }

        return $conversation->messages()
            ->latest('id')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $message) => ['question' => $message->question, 'answer' => $message->answerWithOutcomes()])
            ->values()
            ->all();
    }

    /** Saves the question and answer to the user's chat (a new one when none was given). */
    private function save(?AssistantConversation $conversation, array $validated, array $reply, User $user, Domain $domain, ?AiUsage $usage): AssistantMessage
    {
        $conversation ??= AssistantConversation::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'title' => Str::limit(Str::squish($validated['message']), 77),
            'language' => $validated['language'] ?? 'en',
            'last_message_at' => now(),
        ]);

        $message = $conversation->messages()->create([
            'question' => $validated['message'],
            'answer' => $reply['answer'],
            'links' => $reply['links'] ?? [],
            'pending_actions' => $reply['pending_actions'] ?? [],
            'forms' => $reply['forms'] ?? [],
            'tools_called' => $reply['tools_called'] ?? [],
            'page' => $validated['page'] ?? null,
            'ai_usage_id' => $usage?->id,
        ]);
        $conversation->update(['last_message_at' => now(), 'language' => $validated['language'] ?? $conversation->language]);

        foreach ($reply['pending_actions'] ?? [] as $action) {
            $this->pending->attachMessage($action['token'], $message->id);
        }

        return $message;
    }

    /** Records on the saved chat message what became of a change card. */
    private function settleSavedCard(array $pending, string $token, string $state, ?string $message, ?array $errors = null): void
    {
        if (isset($pending['message_id'])) {
            AssistantMessage::query()->find($pending['message_id'])?->settleAction($token, $state, $message, $errors);
        }
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
