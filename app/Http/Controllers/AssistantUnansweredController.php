<?php

namespace App\Http\Controllers;

use App\Models\AssistantUnansweredQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admins: questions the AI assistant couldn't help with, across all organizations, to
 * decide which guides and tools to add next.
 */
class AssistantUnansweredController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved'])],
        ]);
        $status = $validated['status'] ?? 'open';

        $questions = AssistantUnansweredQuestion::query()
            ->with(['user:id,name,email', 'organization:id,name,name_slug'])
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'), fn ($q) => $q->whereNotNull('resolved_at'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // The most-asked gaps first.
        $topTopics = AssistantUnansweredQuestion::query()
            ->open()
            ->whereNotNull('topic')
            ->selectRaw('LOWER(topic) as topic, COUNT(*) as total')
            ->groupByRaw('LOWER(topic)')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return Inertia::render('Messages/AssistantUnanswered', [
            'questions' => $questions,
            'topTopics' => $topTopics,
            'status' => $status,
            'openCount' => AssistantUnansweredQuestion::query()->open()->count(),
        ]);
    }

    public function resolve(AssistantUnansweredQuestion $question): RedirectResponse
    {
        $question->update(['resolved_at' => $question->resolved_at ? null : now()]);

        return back()->with('success', $question->resolved_at ? 'Marked as resolved.' : 'Reopened.');
    }
}
