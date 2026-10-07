<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordLinkController extends Controller
{
    protected AuthServiceClient $authService;

    public function __construct(AuthServiceClient $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Create setup password link for user.
     * POST /api/admin/users/{userId}/setup-password-link
     *
     * {userId} adalah id user LOKAL — dipetakan ke uuid Auth Service.
     * Body opsional: {channel: 'email'} untuk mengirim link via email.
     */
    public function createSetupLink(Request $request, string $userId): JsonResponse
    {
        $channel = $this->resolveChannel($request);
        if ($channel === false) {
            return response()->json([
                'success' => false,
                'message' => 'Channel pengiriman belum didukung. Gunakan email.',
            ], 422);
        }

        $uuid = $this->resolveIdpUuid($userId);
        if (! $uuid) {
            return response()->json([
                'success' => false,
                'message' => 'User belum tertaut ke Auth Service. Edit & simpan data pegawai untuk menautkan.',
            ], 422);
        }

        $result = $this->authService->createSetupPasswordLink($uuid, $channel);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to create setup password link',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Create reset password link for user.
     * POST /api/admin/users/{userId}/reset-password-link
     *
     * {userId} adalah id user LOKAL — dipetakan ke uuid Auth Service.
     * Body opsional: {channel: 'email'} untuk mengirim link via email.
     */
    public function createResetLink(Request $request, string $userId): JsonResponse
    {
        $channel = $this->resolveChannel($request);
        if ($channel === false) {
            return response()->json([
                'success' => false,
                'message' => 'Channel pengiriman belum didukung. Gunakan email.',
            ], 422);
        }

        $uuid = $this->resolveIdpUuid($userId);
        if (! $uuid) {
            return response()->json([
                'success' => false,
                'message' => 'User belum tertaut ke Auth Service. Edit & simpan data pegawai untuk menautkan.',
            ], 422);
        }

        $result = $this->authService->createResetPasswordLink($uuid, $channel);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to create reset password link',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Channel pengiriman yang didukung. Null = tanpa pengiriman (return url saja).
     * Mengembalikan false bila channel tidak didukung.
     */
    protected function resolveChannel(Request $request): string|false|null
    {
        $channel = $request->input('channel');
        if ($channel === null || $channel === '') {
            return null;
        }
        if ($channel === 'email') {
            return 'email';
        }

        return false;
    }

    /**
     * Petakan id lokal ke uuid IdP. Mengembalikan null bila user tidak ada
     * atau belum tertaut (data lama sebelum provisioning dua arah).
     */
    protected function resolveIdpUuid(string $userId): ?string
    {
        $user = User::find($userId);

        return $user?->auth_service_uuid ?: null;
    }
}
