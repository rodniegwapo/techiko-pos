<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * StartSession that won't hand a browser back a session it has been moved off.
 *
 * Switching who a browser is signed in as (starting or stopping impersonation) moves it to a new
 * session id. A request the page sent just before the switch still carries the old id, and when it
 * answers afterwards StartSession would set that old cookie again, and save the old session over
 * itself, quietly putting the browser back as whoever it was before. A retired session is
 * therefore neither saved nor sent back.
 */
class StartSessionUnlessRetired extends StartSession
{
    /** Marks a session id the browser has just been moved off. */
    public static function retire(string $sessionId): void
    {
        Cache::put(self::key($sessionId), true, now()->addMinutes((int) config('session.lifetime', 120)));
    }

    public static function isRetired(string $sessionId): bool
    {
        return Cache::has(self::key($sessionId));
    }

    private static function key(string $sessionId): string
    {
        return 'retired-session:'.hash('sha256', $sessionId);
    }

    protected function addCookieToResponse(Response $response, Session $session)
    {
        if (self::isRetired($session->getId())) {
            return;
        }

        parent::addCookieToResponse($response, $session);
    }

    protected function saveSession($request)
    {
        if (self::isRetired($this->manager->driver()->getId())) {
            return;
        }

        parent::saveSession($request);
    }
}
