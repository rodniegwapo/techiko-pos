<?php

namespace App\Services\Ai\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Throwable;

/**
 * Runs a laravel/ai prompt and turns API failures into messages a shop owner can act on.
 */
trait HandlesAiErrors
{
    /**
     * Runs one prompt (tool calls included) and returns its text.
     *
     * @param  Closure(): ?string  $call
     */
    protected function send(Closure $call): string
    {
        $who = class_basename(static::class);

        try {
            $text = trim((string) $call());
        } catch (RateLimitedException|ProviderOverloadedException $e) {
            throw new RuntimeException('The AI assistant is busy right now. Please try again in a minute.', 0, $e);
        } catch (ProviderConnectionException $e) {
            Log::warning($who.' could not reach the API', ['message' => $e->getMessage()]);
            throw new RuntimeException('Could not reach the AI assistant. Check the internet connection and try again.', 0, $e);
        } catch (InsufficientCreditsException $e) {
            Log::warning($who.' has no API credit left', ['message' => $e->getMessage()]);
            throw new RuntimeException('The AI assistant could not answer right now. Please try again later.', 0, $e);
        } catch (Throwable $e) {
            Log::warning($who.' request failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            throw new RuntimeException('The AI assistant could not answer right now. Please try again later.', 0, $e);
        }

        // An empty answer also covers one the provider blocked, or a question that ran out of steps.
        if ($text === '') {
            throw new RuntimeException($this->emptyAnswerMessage());
        }

        return $text;
    }

    protected function emptyAnswerMessage(): string
    {
        return 'The AI assistant could not answer this one. Try asking something more specific.';
    }

    public function isConfigured(): bool
    {
        return (string) config('ai.providers.'.$this->provider().'.key') !== '';
    }
}
