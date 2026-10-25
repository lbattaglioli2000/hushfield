<?php

namespace App\Http\Middleware;

use App\Exceptions\WorldActionRefused;
use App\Models\User;
use App\Models\WorldSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every in-world request carries the world session token. A token that is no
 * longer the account's current one is rejected with 409.
 */
final class EnsureWorldSession
{
    public const string HEADER = 'X-World-Session';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $request->header(self::HEADER) ?? $request->input('session_token');
        $session = WorldSession::query()->where('user_id', $user->id)->first();

        if ($user->isBanned()) {
            throw WorldActionRefused::banned();
        }

        if ($session === null) {
            throw WorldActionRefused::sessionExpired();
        }

        if (! is_string($token) || ! hash_equals($session->session_token, $token)) {
            throw WorldActionRefused::sessionSuperseded();
        }

        $user->setRelation('worldSession', $session);

        return $next($request);
    }
}
