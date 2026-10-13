<?php

namespace App\Services\Ai;

/**
 * Scores one AI assistant answer against what a question expects (tests/Fixtures/
 * assistant-questions.json), for `php artisan assistant:eval`.
 */
class AssistantEval
{
    /**
     * What is wrong with the answer, if anything.
     *
     * @param  array{any_tool?: list<string>, no_tool?: list<string>, guide?: string, answer_mentions?: list<string>}  $expect
     * @param  array{answer: string, tools_called: list<array{name: string, arguments: array}>}  $reply
     * @return list<string> Empty when the answer passes.
     */
    public static function problems(array $expect, array $reply): array
    {
        $called = array_column($reply['tools_called'], 'name');
        $problems = [];

        if (! empty($expect['any_tool']) && array_intersect($expect['any_tool'], $called) === []) {
            $problems[] = 'used none of: '.implode(', ', $expect['any_tool']);
        }

        foreach ($expect['no_tool'] ?? [] as $tool) {
            if (in_array($tool, $called, true)) {
                $problems[] = 'should not use '.$tool;
            }
        }

        if (! empty($expect['guide'])) {
            $guides = array_map(
                fn ($call) => $call['arguments']['key'] ?? null,
                array_filter($reply['tools_called'], fn ($call) => $call['name'] === 'get_guide'),
            );
            if (! in_array($expect['guide'], $guides, true)) {
                $problems[] = 'did not open the guide '.$expect['guide'].($guides ? ' (opened '.implode(', ', array_filter($guides)).')' : '');
            }
        }

        foreach ($expect['answer_mentions'] ?? [] as $text) {
            if (mb_stripos($reply['answer'], $text) === false) {
                $problems[] = 'answer does not mention "'.$text.'"';
            }
        }

        return $problems;
    }
}
