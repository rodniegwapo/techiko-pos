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
    public const TOPICS = ['overview', 'health', 'income_statement', 'receivables', 'payables', 'cash_flow', 'balance_sheet', 'expenses', 'metric'];

    public const LANGUAGES = ['en', 'taglish'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the financial assistant inside Techiko POS, a point-of-sale system used by small businesses in the Philippines. The owner reading your answer runs a shop, not an accounting department: explain their numbers the way a trusted, plain-spoken bookkeeper friend would.

You are given a JSON fact sheet. Every figure in it was calculated by the POS from the business's own records.
- Use only the figures in the fact sheet. Never invent, estimate or recalculate a number; if a figure you would need is missing, say it isn't available.
- Amounts are in Philippine pesos; write them like ₱12,345.
- "revenue" is what customers paid less the VAT collected. "gross_profit" is revenue less what the goods sold cost (cogs). "net_profit" is gross profit less operating expenses, plus other income, less other expenses. If "expenses_recorded" is false, no expenses have been entered yet: say net profit is overstated until they are.
- Profit and cash are different things. The cash_flow section shows money in and out, and its "bridge" lists why the change in money differs from net profit (credit sales not collected, stock bought, bills not yet paid, owner withdrawals). Use it when asked about cash.
- Bank balances, loans, equipment and owner investments are not recorded in the system; the balance sheet leaves them out.
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

        $text = $this->ask($question, $facts);
        Cache::put($cacheKey, $text, now()->addHours(6));

        return ['text' => $text, 'cached' => false];
    }

    /** @param  array<string, mixed>  $facts */
    private function ask(string $question, array $facts): string
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $client->messages->create(
                model: $this->model(),
                maxTokens: 4000,
                system: self::SYSTEM_PROMPT,
                outputConfig: ['effort' => 'low'],
                messages: [[
                    'role' => 'user',
                    'content' => "<fact_sheet>\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        ."\n</fact_sheet>\n\n".$question,
                ]],
            );
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
}
