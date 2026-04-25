<?php

namespace App\Http\Controllers;

use App\Events\ConversationMessageCreated;
use App\Http\Requests\StoreCustomerMessageRequest;
use App\Http\Requests\StoreStaffMessageRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function store(StoreCustomerMessageRequest $request): RedirectResponse
    {
        $user = $request->user();
        $conversation = Conversation::firstOrCreate(
            ['user_id' => $user->id],
        );

        $message = $conversation->messages()->create([
            'author_user_id' => $user->id,
            'body' => $request->validated('body'),
        ]);
        $message->load('author');
        broadcast(new ConversationMessageCreated($message))->toOthers();

        return back()->with('success', 'Message sent. We will get back to you as soon as we can.');
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Conversation::class);
        $openId = $request->query('c');
        if ($openId !== null && $openId === '') {
            $openId = null;
        }

        $conversations = Conversation::query()
            ->with(['user', 'lastMessage'])
            ->withCount(['messages as unread_for_staff' => function ($q) {
                $q->whereNull('read_by_staff_at')
                    ->whereColumn('author_user_id', 'conversations.user_id');
            }])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $thread = $this->buildThreadData($request, $openId);

        return Inertia::render('Messages/Index', [
            'conversations' => $conversations,
            'thread' => $thread,
            'openConversationId' => $openId ? (int) $openId : null,
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('markAsReadByStaff', $conversation);
        $this->markCustomerMessagesAsReadByStaff($conversation);

        return back()->with('success', 'Marked as read.');
    }

    public function storeStaff(StoreStaffMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $message = $conversation->messages()->create([
            'author_user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);
        $message->load('author');
        broadcast(new ConversationMessageCreated($message))->toOthers();

        return back()->with('success', 'Message sent.');
    }

    public function messagesJson(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        if ($request->user()->isSuperUser()) {
            $this->markCustomerMessagesAsReadByStaff($conversation);
        }

        $messages = $conversation->messages()
            ->with('author')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'messages' => $this->mapMessages($messages, $conversation),
        ]);
    }

    private function buildThreadData(Request $request, $openId): ?array
    {
        if ($openId === null || $openId === '') {
            return null;
        }
        $conversation = Conversation::query()
            ->whereKey($openId)
            ->with('user')
            ->first();
        if (! $conversation) {
            return null;
        }
        $this->authorize('view', $conversation);
        if ($request->user()->isSuperUser()) {
            $this->markCustomerMessagesAsReadByStaff($conversation);
        }
        $conversation->load(['messages' => function ($q) {
            $q->with('author')->orderBy('created_at', 'asc');
        }]);

        return [
            'id' => $conversation->id,
            'user' => [
                'id' => $conversation->user->id,
                'name' => $conversation->user->name,
                'email' => $conversation->user->email,
            ],
            'messages' => $this->mapMessages($conversation->messages, $conversation),
        ];
    }

    private function markCustomerMessagesAsReadByStaff(Conversation $conversation): void
    {
        $userId = (int) $conversation->user_id;
        $conversation->messages()
            ->where('author_user_id', $userId)
            ->whereNull('read_by_staff_at')
            ->update(['read_by_staff_at' => now()]);
    }

    private function mapMessages($messages, Conversation $conversation): array
    {
        $customerId = (int) $conversation->user_id;

        return $messages->map(function (ConversationMessage $m) use ($customerId) {
            return [
                'id' => $m->id,
                'body' => $m->body,
                'author_user_id' => $m->author_user_id,
                'is_from_customer' => (int) $m->author_user_id === $customerId,
                'created_at' => $m->created_at?->toIso8601String(),
                'author' => $m->author ? [
                    'id' => $m->author->id,
                    'name' => $m->author->name,
                    'email' => $m->author->email,
                ] : null,
            ];
        })->values()->all();
    }
}
