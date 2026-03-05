<?php

declare(strict_types=1);

namespace PointerDev\PointerAI\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class PointerAIRuntimeSessionMiddleware
{
    public function __construct(
        private readonly PointerAIRuntimeSessionManager $manager
    ) {}

    /**
     * Bootstrap PointerAI runtime session for authenticated Laravel users.
     *
     * If user is authenticated and package runtime auth is enabled, middleware:
     * - maps Auth::user() to server-minted PointerAI end-user token
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
                // Do not fail the host application request if PointerAI is unavailable.
            }
        }

        return $next($request);
    }
}
