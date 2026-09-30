<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
     */
    public function createSetupLink(Request $request, string $userId): JsonResponse
    {
        $result = $this->authService->createSetupPasswordLink($userId);

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
     */
    public function createResetLink(Request $request, string $userId): JsonResponse
    {
        $result = $this->authService->createResetPasswordLink($userId);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to create reset password link',
            ], 422);
        }

        return response()->json($result);
    }
}
