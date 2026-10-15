<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The user's own saved AI assistant chats, for the History list in the chat drawer. */
class AssistantConversationController extends Controller
{
    public function index(Request $request, Domain $domain): JsonResponse
    {
        $conversations = AssistantConversation::query()
            ->ownedBy($request->user(), $domain)
            ->latest('last_message_at')
            ->latest('id')
            ->paginate(20, ['id', 'title', 'last_message_at']);

        return response()->json($conversations);
    }

    public function show(Request $request, Domain $domain, int $conversation): JsonResponse
    {
        $chat = AssistantConversation::query()->ownedBy($request->user(), $domain)->findOrFail($conversation);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'language' => $chat->language,
            'turns' => $chat->messages()->oldest('id')->get()->map(fn (AssistantMessage $message) => $message->toTurn()),
        ]);
    }

    public function destroy(Request $request, Domain $domain, int $conversation): JsonResponse
    {
        AssistantConversation::query()->ownedBy($request->user(), $domain)->findOrFail($conversation)->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }
}
