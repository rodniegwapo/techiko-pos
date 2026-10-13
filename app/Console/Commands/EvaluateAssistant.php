<?php

namespace App\Console\Commands;

use App\Helpers;
use App\Models\Domain;
use App\Models\User;
use App\Services\Ai\AppAssistant;
use App\Services\Ai\AssistantEval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * Asks the AI assistant a set of real questions (tests/Fixtures/assistant-questions.json) as one
 * user and reports whether it used the right guide or tool for each. It calls the real AI, so it
 * costs API usage: run it by hand after changing guides, tools or the prompt, not in CI.
 * Nothing is changed: proposed changes stay unconfirmed and expire.
 */
class EvaluateAssistant extends Command
{
    protected $signature = 'assistant:eval
        {--user= : Email of the user to ask as (an owner or admin, so every guide and tool is available)}
        {--only=* : Only these question ids}
        {--limit= : Ask at most this many questions}
        {--sleep=0 : Seconds to wait between questions (to stay under the AI provider\'s rate limit)}
        {--file= : Question file (default tests/Fixtures/assistant-questions.json)}
        {--min= : Fail (exit code 1) when fewer than this percentage of answered questions pass}';

    protected $description = 'Ask the AI assistant real questions and check it uses the right guides and tools';

    public function handle(AppAssistant $assistant): int
    {
        $user = User::query()->where('email', (string) $this->option('user'))->first();
        $domain = $user ? Domain::query()->where('name_slug', $user->domain)->first() : null;
        if (! $user || ! $domain) {
            $this->error('Give --user= the email of a user who belongs to a business.');

            return self::FAILURE;
        }
        if (! $assistant->isConfigured()) {
            $this->error('The AI is not set up: add the provider\'s API key (e.g. GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $file = $this->option('file') ?: base_path('tests/Fixtures/assistant-questions.json');
        $questions = collect(json_decode((string) @file_get_contents($file), true)['questions'] ?? [])
            ->when($this->option('only'), fn ($all, $only) => $all->whereIn('id', $only))
            ->when($this->option('limit'), fn ($all, $limit) => $all->take((int) $limit))
            ->values();
        if ($questions->isEmpty()) {
            $this->error("No questions found in {$file}.");

            return self::FAILURE;
        }

        // The active store and the pages' rules depend on who is signed in.
        Auth::setUser($user);
        $location = Helpers::getActiveLocation($domain);
        $this->info('Asking '.$questions->count().' '.Str::plural('question', $questions->count())." as {$user->email} ({$domain->name}".($location ? ", {$location->name}" : '').')');

        $rows = [];
        $passed = 0;
        $answered = 0;
        foreach ($questions as $i => $q) {
            if ($i > 0 && (int) $this->option('sleep') > 0) {
                sleep((int) $this->option('sleep'));
            }

            try {
                $reply = $assistant->reply($q['question'], [], $user, $domain, $location, null, $q['language'] ?? 'en');
            } catch (RuntimeException $e) {
                $rows[] = [$q['id'], 'ERROR', '', $e->getMessage()];
                $limit = $e->getPrevious();
                if ($limit instanceof RateLimitedException) {
                    $this->table(['Question', 'Result', 'Tools used', 'Details'], $rows);
                    $this->error('The AI provider\'s rate limit was reached: '.$this->providerMessage($limit));
                    $this->line('Run again later, add --sleep=, or use a paid plan / another model.');

                    return self::FAILURE;
                }

                continue;
            }

            $answered++;
            $problems = AssistantEval::problems($q['expect'] ?? [], $reply);
            $problems === [] && $passed++;
            $rows[] = [
                $q['id'],
                $problems === [] ? 'pass' : 'FAIL',
                implode(', ', array_unique(array_column($reply['tools_called'], 'name'))),
                $problems === [] ? mb_strimwidth(preg_replace('/\s+/', ' ', $reply['answer']), 0, 70, '…') : implode('; ', $problems),
            ];
        }

        $this->table(['Question', 'Result', 'Tools used', 'Details'], $rows);
        $rate = $answered > 0 ? round($passed / $answered * 100) : 0;
        $this->info("Passed {$passed} of {$answered} answered ({$rate}%).".($answered < $questions->count() ? ' '.($questions->count() - $answered).' could not be answered (errors).' : ''));

        $min = $this->option('min');

        return $min !== null && $rate < (int) $min ? self::FAILURE : self::SUCCESS;
    }

    private function providerMessage(RateLimitedException $e): string
    {
        $cause = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return preg_match('/"message":\s*"([^"]+)"/', $cause, $m) ? $m[1] : mb_strimwidth($cause, 0, 200, '…');
    }
}
