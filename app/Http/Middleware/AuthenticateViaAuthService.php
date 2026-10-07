<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserRole;
use App\Services\UserSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuthenticateViaAuthService
{
    public function __construct(protected UserSyncService $userSyncService) {}

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Bearer token required.',
            ], 401);
        }

        // Check cache first
        $cacheKey = 'auth_service_token_'.sha1($token);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            $user = $this->userSyncService->syncFromAuthServicePayload($cached);
            $this->resolveAndCacheRoles($user, $request);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        // Validate token with Auth Service
        $authServiceUrl = rtrim(config('auth_service.url', 'http://localhost:8000'), '/');

        $response = Http::withToken($token)
            ->timeout(10)
            ->get("{$authServiceUrl}/api/auth/me");

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('message', 'Invalid or expired token'),
            ], 401);
        }

        $userData = $response->json();

        // Auth service returns {success: true, data: {id, full_name, email, phone_number}}
        if (! isset($userData['data']['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token response from Auth Service',
            ], 401);
        }

        $authUser = $userData['data'];

        // Cache for 5 minutes (token TTL)
        Cache::put($cacheKey, $authUser, 300);

        $user = $this->userSyncService->syncFromAuthServicePayload($authUser);
        $this->resolveAndCacheRoles($user, $request);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Resolve user roles from local employee profile and cache in session.
     */
    protected function resolveAndCacheRoles(User $user, Request $request): void
    {
        $roles = [];

        // 1. Check manually assigned active roles (user_roles table)
        $manualRoles = UserRole::where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with('role:id,name')
            ->get()
            ->pluck('role.name')
            ->toArray();

        if (! empty($manualRoles)) {
            $roles = $manualRoles;
        } else {
            // Tanpa role manual, user dianggap reporter.
            // (Assign role sepenuhnya manual via user_roles oleh admin.)
            $roles = ['reporter'];
        }

        // Cache in request (no session for API routes)
        $request->merge(['user_roles' => $roles]);
        view()->share('user_roles', $roles);
    }
}
