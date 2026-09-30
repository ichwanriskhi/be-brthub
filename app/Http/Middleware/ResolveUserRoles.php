<?php

namespace App\Http\Middleware;

use App\Models\PositionRoleMapping;
use App\Models\UserRole;
use App\Services\AuthServiceClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResolveUserRoles
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! auth()->check()) {
            return $next($request);
        }

        $user = auth()->user();

        // 1. Cek apakah roles udah di-cache di session
        if ($request->session()->has("user_roles_{$user->id}")) {
            $roles = $request->session()->get("user_roles_{$user->id}");
            $request->merge(['user_roles' => $roles]);

            return $next($request);
        }

        // 2. Ambil role aktif dari DB BRTHub (user_roles)
        $roles = [];

        try {
            $roleRecords = UserRole::where('user_id', $user->id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->with('role:name,label')
                ->get();

            $roles = $roleRecords->pluck('role.name')->toArray();
        } catch (\Throwable $e) {
            Log::error('Failed to resolve user roles', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Fallback: jika karyawan TAPI belum ada role manual
        if (empty($roles)) {
            $email = $user->email ?? null;

            // Coba ambil dari Auth Service /api/auth/me
            if ($email && config('auth_service.token')) {
                try {
                    $client = new AuthServiceClient;
                    $result = $client->getUser(config('auth_service.token'));

                    if ($result['success'] && isset($result['data']['employee'])) {
                        $emp = $result['data']['employee'];

                        // Cek position_role_mappings
                        $mappedRoles = PositionRoleMapping::where('position_id', $emp['position_id'])
                            ->where('is_default', true)
                            ->with('role:name')
                            ->get()
                            ->pluck('role.name')
                            ->toArray();

                        $roles = $mappedRoles;
                    }
                } catch (\Throwable $e) {
                    Log::warning('AuthService fallback failed, using default role', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Default non-staff → reporter
            if (empty($roles)) {
                $roles = ['reporter'];
            }
        }

        // 4. Cache ke session & inject ke request
        $request->session()->put("user_roles_{$user->id}", $roles);
        $request->merge(['user_roles' => $roles]);

        // 5. Set global untuk view
        view()->share('user_roles', $roles);

        return $next($request);
    }
}
