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
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        $userRoles = $request->user_roles ?? $user->getActiveRoles();

        // Jika tidak ada roles assigned (belum di-assign oleh admin), block akses
        if (empty($userRoles)) {
            return abort(403, 'User belum memiliki role yang valid.');
        }

        // Cek apakah user punya minimal satu dari role yang diminta
        $allowed = false;
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            return abort(403, 'Anda tidak memiliki akses ke halaman ini. Required role(s): '.implode(', ', $roles));
        }

        return $next($request);
    }
}
