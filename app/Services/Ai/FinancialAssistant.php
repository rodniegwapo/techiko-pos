<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Explains a business's figures in plain language with Claude. It is handed numbers that were
 * already worked out (FinancialReportService) and told to use only those, so it explains the
 * books rather than doing the sums.
 */
class FinancialAssistant
{
    public const TOPICS = ['overview', 'health', 'income_statement', 'receivables', 'payables', 'cash_flow', 'balance_sheet', 'expenses', 'review', 'metric'];

    /** Steps (tool calls and the answer) a question may take before giving up. */
    private const MAX_QUESTION_STEPS = 8;

    private const QUESTION_PROMPT = <<<'PROMPT'
You are the financial assistant inside Techiko POS, a point-of-sale system used by small businesses in the Philippines. The owner is asking about their own business. Answer the way a trusted, plain-spoken bookkeeper friend would.

Use the tools to get the figures you need; they read the business's own records. Rules:
- Base every number on what the tools return. Don't guess or invent figures. When you combine figures (a difference, a share), say which figures you used.
- If the tools can't answer the question, say so plainly and suggest which Finance page would help.
- Amounts are in Philippine pesos; write them like ₱12,345. Name the dates you looked at.
- "revenue" excludes VAT. Gross profit = revenue − cost of goods sold (and stock written off). Net profit = gross profit − operating expenses + other income − other expenses. If "expenses_recorded" is false, say net profit is overstated until expenses are recorded.
- Customers are anonymised (Customer A, B…); refer the owner to the Customer credit page for names.
- Avoid accounting jargon. Suggestions are things to consider, never guarantees or professional financial advice.

Answer in plain text: a direct answer in 1 to 4 sentences, then, only if useful, up to 3 lines starting with "- " for details or next steps. Keep it under 150 words. No markdown headings, bold or tables.
PROMPT;

    public const LANGUAGES = ['en', 'taglish'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the financial assistant inside Techiko POS, a point-of-sale system used by small businesses in the Philippines. The owner reading your answer runs a shop, not an accounting department: explain their numbers the way a trusted, plain-spoken bookkeeper friend would.

You are given a JSON fact sheet. Every figure in it was calculated by the POS from the business's own records.
- Use only the figures in the fact sheet. Never invent, estimate or recalculate a number; if a figure you would need is missing, say it isn't available.
- Amounts are in Philippine pesos; write them like ₱12,345.
- "revenue" is what customers paid less the VAT collected. "gross_profit" is revenue less what the goods sold cost (cogs). "net_profit" is gross profit less operating expenses, plus other income, less other expenses. If "expenses_recorded" is false, no expenses have been entered yet: say net profit is overstated until they are.
- Profit and cash are different things. The cash_flow section shows money in and out, and its "bridge" lists why the change in money differs from net profit (credit sales not collected, stock bought, bills not yet paid, loans, equipment bought, owner withdrawals). Use it when asked about cash.
- Bank and e-wallet balances are what the owner last entered, each with an "as_of" date; say how current they are. Equipment is shown at its value after depreciation.
- Explain why numbers moved by comparing with the previous period, when the fact sheet has it.
- Avoid accounting jargon. If you must use a term, explain it in a few words.
- Any suggestion is a suggestion for the owner to consider, never a guarantee or professional financial advice.

Format your answer as plain text:
1. A short summary of 2 to 4 sentences answering the question.
2. A line "What to watch:" followed by up to 3 lines starting with "- ".
3. A line "Suggested next steps:" followed by up to 3 lines starting with "- ".
Leave out a section if there is nothing useful to put in it. Keep the whole answer under 180 words. No headings other than those, no markdown bold or tables.
PROMPT;

    private const TOPIC_PROMPTS = [
        'overview' => 'Explain my finances for this period: how is the business doing and why?',
        'health' => 'Look at my business health check. What deserves my attention first, and why?',
        'income_statement' => 'Explain my income statement: how much did I sell, what did the goods cost, how much gross profit did I keep, and why did it change from the previous period?',
        'receivables' => 'How much money do my customers owe me, how much of it is overdue, and what should I do about it?',
        'payables' => 'How much do I owe my suppliers, what is due soon or overdue, and what should I plan for?',
        'cash_flow' => 'Explain my cash flow: where did my money come from, where did it go, and why did my cash change differently from my profit?',
        'balance_sheet' => 'Explain my financial position: what does my business own, what does it owe, and what is it worth?',
        'expenses' => 'Explain my expenses: where is my money going, which costs grew, and am I spending too much compared with my sales?',
        'review' => 'This is my monthly business review. Summarize how the month went compared with the month before, and what matters most for next month.',
    ];

    public function isConfigured(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{text: string, cached: bool}
     */
    public function explain(string $topic, array $facts, string $language = 'en', ?string $metricLabel = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The AI assistant is not set up yet. Add ANTHROPIC_API_KEY to the server settings.');
        }

        $question = $topic === 'metric'
            ? 'Explain this number to me in simple terms and why it changed: "'.$metricLabel.'".'
            : self::TOPIC_PROMPTS[$topic];

        if ($language === 'taglish') {
            $question .= ' Answer in Taglish (a natural mix of Tagalog and English, as Filipino shop owners speak).';
        }

        $cacheKey = 'finance-ai:'.hash('sha256', json_encode([$topic, $metricLabel, $language, $facts, $this->model()]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return ['text' => $cached, 'cached' => true];
        }

        $message = $this->send(fn (Client $client) => $client->messages->create(
            model: $this->model(),
            maxTokens: 4000,
            system: self::SYSTEM_PROMPT,
            outputConfig: ['effort' => 'low'],
            messages: [[
                'role' => 'user',
                'content' => "<fact_sheet>\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    ."\n</fact_sheet>\n\n".$question,
            ]],
        ));
        $text = $this->textOf($message);
        Cache::put($cacheKey, $text, now()->addHours(6));

        return ['text' => $text, 'cached' => false];
    }

    /**
     * Answers an owner's own question about the business. Claude calls the finance tools for the
     * figures it needs (as many rounds as it takes, within a limit), then answers in plain words.
     *
     * @param  list<array{question: string, answer: string}>  $history  The last few questions and answers, for follow-ups.
     */
    public function answer(string $question, array $history, FinanceTools $tools, string $businessName, string $language = 'en'): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The AI assistant is not set up yet. Add ANTHROPIC_API_KEY to the server settings.');
        }

        $messages = [];
        foreach (array_slice($history, -4) as $turn) {
            $messages[] = ['role' => 'user', 'content' => (string) $turn['question']];
            $messages[] = ['role' => 'assistant', 'content' => (string) $turn['answer']];
        }
        $messages[] = ['role' => 'user', 'content' => $language === 'taglish'
            ? $question."\n\n(Answer in Taglish, a natural mix of Tagalog and English.)"
            : $question];

        $system = self::QUESTION_PROMPT."\n\nBusiness: ".$businessName.'. Today is '.today()->format('l, F j, Y').' ('.today()->toDateString().').';

        for ($step = 0; $step < self::MAX_QUESTION_STEPS; $step++) {
            $response = $this->send(fn (Client $client) => $client->messages->create(
                model: $this->questionModel(),
                maxTokens: 8000,
                system: $system,
                tools: $tools->definitions(),
                outputConfig: ['effort' => 'low'],
                messages: $messages,
            ));

            if ($response->stopReason !== 'tool_use') {
                return $this->textOf($response);
            }

            $results = [];
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $tools->run($block->name, (array) $block->input),
                    ];
                }
            }
            // The assistant turn goes back unchanged (thinking blocks included), then the results.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new RuntimeException('That question needed too many steps to answer. Try asking something more specific.');
    }

    /** Runs one API call, turning API failures into messages an owner can act on. */
    private function send(callable $call): object
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $call($client);
        } catch (RateLimitException $e) {
            throw new RuntimeException('The AI assistant is busy right now. Please try again in a minute.', 0, $e);
        } catch (APIStatusException $e) {
            Log::warning('Financial assistant request failed', ['type' => $e->type?->value, 'message' => $e->getMessage()]);
            throw new RuntimeException('The AI assistant could not answer right now. Please try again later.', 0, $e);
        } catch (APIConnectionException $e) {
            Log::warning('Financial assistant could not reach the API', ['message' => $e->getMessage()]);
            throw new RuntimeException('Could not reach the AI assistant. Check the internet connection and try again.', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The AI assistant could not answer this one. The figures on the page are still accurate.');
        }

        return $message;
    }

    private function textOf(object $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('The AI assistant returned an empty answer. Please try again.');
        }

        return trim($text);
    }

    private function model(): string
    {
        return (string) config('services.anthropic.model', 'claude-haiku-5-5');
    }

    /** Questions need judgment about which figures to fetch, so they use a stronger model. */
    private function questionModel(): string
    {
        return (string) config('services.anthropic.question_model', 'claude-sonnet-5-5');
    }
}
