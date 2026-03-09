<?php

declare(strict_types=1);

namespace PointerDev\AIChat\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AIChatRuntimeSessionMiddleware
{
    public function __construct(
        private readonly AIChatRuntimeSessionManager $manager
    ) {}

    /**
     * Bootstrap PointerDev AI runtime session for authenticated Laravel users.
     *
     * If user is authenticated and package runtime auth is enabled, middleware:
     * - maps Auth::user() to server-minted PointerDev AI end-user token
     * - exchanges/refreshes runtime session token
     * - persists runtime session token in Laravel session store
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasSession()) {
            return $next($request);
        }

        $user = Auth::user();
        if ($user !== null) {
            try {
                $this->manager->bootstrapForUser($request, $user);
            } catch (Throwable $e) {
                // Do not fail the host application request if PointerDev AI is unavailable.
            }
        }

        return $next($request);
    }
}
