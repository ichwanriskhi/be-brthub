<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUserRoles
{
    /**
     * Handle the incoming request.
     *
     * Usage in route or middleware group:
     * $route->middleware('roles:admin,reviewer')
     */
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        // Pada route API (mis. di belakang `auth.authservice`) tidak ada
        // session guard, user disediakan via request user-resolver.
        $user = $request->user() ?? Auth::user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return redirect()->route('login');
        }

        $userRoles = $request->user_roles ?? $user->getActiveRoles();

        // Jika tidak ada roles assigned (belum di-assign oleh admin), block akses
        if (empty($userRoles)) {
            return $this->deny($request, 'User belum memiliki role yang valid.', $roles);
        }

        // Cek apakah user punya minimal satu dari role yang diminta
        // (case-insensitive: nama role di DB lowercase, parameter bisa apa pun).
        $allowed = false;
        $normalizedUserRoles = array_map(
            fn ($name) => strtolower(trim((string) $name)),
            is_array($userRoles) ? $userRoles : []
        );
        foreach ($roles as $role) {
            if (in_array(strtolower(trim($role)), $normalizedUserRoles, true)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            return $this->deny($request, 'Anda tidak memiliki akses ke halaman ini. Required role(s): '.implode(', ', $roles), $roles);
        }

        return $next($request);
    }

    /**
     * Tolak akses: JSON untuk API, redirect/abort untuk web.
     */
    protected function deny(Request $request, string $message, array $roles): mixed
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        // Backward-compatible dengan perilaku web sebelumnya.
        if (empty($request->user_roles) && ! Auth::check()) {
            return redirect()->route('login');
        }

        return abort(403, $message.' Required role(s): '.implode(', ', $roles));
    }
}
