<?php

namespace App\Services\Ai\Concerns;

use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Throwable;

/**
 * Runs a laravel/ai prompt and turns API failures into messages a shop owner can act on.
 */
trait HandlesAiErrors
{
    /** The provider's response to the last successful prompt (tokens used, provider and model), for AI credits. */
    private ?TextResponse $lastResponse = null;

    private const OUT_OF_CREDITS = 'The AI assistant is unavailable: the AI account has run out of credits. Ask your administrator to add credits.';

    /**
     * Runs one prompt (tool calls included) and returns its text.
     *
     * @param  Closure(): ?string  $call
     */
    protected function send(Closure $call): string
    {
        $who = class_basename(static::class);
        $this->lastResponse = null;

        try {
            $text = trim((string) $call());
        } catch (RateLimitedException|ProviderOverloadedException $e) {
            // The provider's own reason (per-minute limit, daily quota, model overloaded) is only in its response.
            $response = $e->getPrevious() instanceof RequestException ? $e->getPrevious()->response : null;
            Log::warning($who.' was turned away by the API', [
                'exception' => class_basename($e),
                'status' => $response?->status(),
                'retry_after' => $response?->header('Retry-After'),
                'error' => $response?->json('error.message') ?? Str::limit((string) $response?->body(), 500),
            ]);
            throw new RuntimeException($this->turnedAwayMessage($response), 0, $e);
        } catch (ProviderConnectionException $e) {
            Log::warning($who.' could not reach the API', ['message' => $e->getMessage()]);
            throw new RuntimeException('Could not reach the AI assistant. Check the internet connection and try again.', 0, $e);
        } catch (InsufficientCreditsException $e) {
            Log::warning($who.' has no API credit left', ['message' => $e->getMessage()]);
            throw new RuntimeException(self::OUT_OF_CREDITS, 0, $e);
        } catch (Throwable $e) {
            Log::warning($who.' request failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            throw new RuntimeException('The AI assistant could not answer right now. Please try again later.', 0, $e);
        }

        // An empty answer also covers one the provider blocked, or a question that ran out of steps.
        if ($text === '') {
            $this->lastResponse = null;
            throw new RuntimeException($this->emptyAnswerMessage());
        }

        return $text;
    }

    /** Keeps the provider's response from inside a send() closure, so the caller can charge AI credits for it. */
    protected function remember(TextResponse $response): TextResponse
    {
        return $this->lastResponse = $response;
    }

    /** The last answer's response, or null when the last prompt failed or none was sent (e.g. a cached explanation). */
    public function lastResponse(): ?TextResponse
    {
        return $this->lastResponse;
    }

    /**
     * What to tell the user when the provider turns a request away: a used-up plan (no credits, or a
     * daily limit that only resets hours later) is not "busy", and retrying in a minute won't help.
     */
    private function turnedAwayMessage(?Response $response): string
    {
        $error = strtolower((string) ($response?->json('error.message') ?? '').' '.(string) ($response?->json('error.code') ?? ''));
        if (Str::contains($error, ['credit', 'insufficient_quota', 'billing'])) {
            return self::OUT_OF_CREDITS;
        }

        $wait = (string) $response?->header('Retry-After');
        $hours = ctype_digit($wait) ? (int) ceil((int) $wait / 3600) : null;
        if (($hours !== null && (int) $wait > 300) || Str::contains($error, 'per day')) {
            return 'The AI assistant has reached its usage limit for today'
                .($hours ? ' and opens again in about '.$hours.' '.Str::plural('hour', $hours) : '')
                .'. Ask your administrator to raise the AI plan\'s limit.';
        }

        return 'The AI assistant is busy right now. Please try again in a minute.';
    }

    protected function emptyAnswerMessage(): string
    {
        return 'The AI assistant could not answer this one. Try asking something more specific.';
    }

    /** Where this feature's AI settings live, e.g. "services.app_assistant". */
    abstract protected function aiConfig(): string;

    public function isConfigured(): bool
    {
        return $this->providers($this->model()) !== [];
    }

    /**
     * The providers to try in order, each with its model: the configured one, then the fallback
     * (when set). One without an API key is left out. laravel/ai moves to the next when a provider
     * is rate limited, overloaded, out of credits or can't be reached in time.
     *
     * @return array<string, string>
     */
    protected function providers(string $model): array
    {
        $providers = [$this->provider() => $model];

        $fallback = (string) config($this->aiConfig().'.fallback_provider');
        $fallbackModel = (string) config($this->aiConfig().'.fallback_model');
        if ($fallback !== '' && $fallbackModel !== '' && ! isset($providers[$fallback])) {
            $providers[$fallback] = $fallbackModel;
        }

        return array_filter($providers, fn (string $model, string $provider) => (string) config('ai.providers.'.$provider.'.key') !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** Seconds to wait for one AI call before giving up (or trying the fallback). */
    protected function timeout(): int
    {
        return max(5, (int) config($this->aiConfig().'.timeout', 30));
    }

    /** The .env setting that holds the active provider's key (OPENAI_API_KEY, GEMINI_API_KEY…), for setup messages. */
    public function keyEnvName(): string
    {
        return strtoupper($this->provider()).'_API_KEY';
    }
}
