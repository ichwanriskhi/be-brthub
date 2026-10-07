<?php

namespace App\Http\Controllers;

use App\Services\AuthServiceClient;
use App\Services\PhoneNumber;
use App\Services\UserSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    protected AuthServiceClient $authService;

    protected UserSyncService $userSync;

    public function __construct(AuthServiceClient $authService, UserSyncService $userSync)
    {
        $this->authService = $authService;
        $this->userSync = $userSync;
    }

    /**
     * Request OTP for login/register.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string', // phone number or email
            'action' => 'required|in:login,register,reset_password,email_login,phone_login,email_otp,phone_otp',
        ]);

        // Map frontend actions to IdP expected actions
        $idpAction = $this->mapActionToIdP($validated['identifier'], $validated['action']);

        $result = $this->authService->requestOtpAuto(
            $validated['identifier'],
            $idpAction
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to send OTP',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Verify OTP and get access token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string',
            'otp_code' => 'required|string|size:6',
            'action' => 'required|in:login,register,reset_password,email_login,phone_login,email_otp,phone_otp',
        ]);

        // Map frontend actions to IdP expected actions
        $idpAction = $this->mapActionToIdP($validated['identifier'], $validated['action']);

        $result = $this->authService->verifyOtpAuto(
            $validated['identifier'],
            $validated['otp_code'],
            $idpAction
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Invalid OTP',
            ], 422);
        }

        // Sync user to local DB if needed
        if (isset($result['user'])) {
            $this->syncUserFromAuthService($result['user']);
        }

        return response()->json($result);
    }

    /**
     * Map frontend action to IdP expected action.
     */
    protected function mapActionToIdP(string $identifier, string $action): string
    {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

        return match (true) {
            $isEmail && $action === 'login' => 'email_login',
            $isEmail && in_array($action, ['register', 'reset_password']) => 'email_otp',
            ! $isEmail && $action === 'login' => 'phone_login',
            ! $isEmail && in_array($action, ['register', 'reset_password']) => 'phone_otp',
            default => $action,
        };
    }

    /**
     * Redirect to Google OAuth.
     */
    public function redirectToGoogle(): JsonResponse
    {
        $url = $this->authService->getGoogleRedirectUrl();

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Handle Google OAuth callback.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $result = $this->authService->exchangeCodeForToken($validated['code']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Google authentication failed',
            ], 422);
        }

        // Sync user to local DB
        if (isset($result['user'])) {
            $this->syncUserFromAuthService($result['user']);
        }

        return response()->json($result);
    }

    /**
     * Refresh access token.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $result = $this->authService->refreshToken($validated['refresh_token']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Token refresh failed',
            ], 401);
        }

        return response()->json($result);
    }

    /**
     * Logout (revoke token).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No token provided',
            ], 401);
        }

        $result = $this->authService->revokeToken($token);

        // Hapus cache introspeksi token ini: tanpa ini, token yang sudah
        // di-revoke tetap diterima middleware s.d. 5 menit (fixed-window).
        Cache::forget('auth_service_token_'.sha1($token));

        // Also revoke local session if exists
        // (implement if using local sessions)

        return response()->json($result);
    }

    /**
     * Get current authenticated user profile, enriched with BRTHub-specific
     * data (roles and employee profile) from the local database.
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No token provided',
            ], 401);
        }

        $result = $this->authService->getUser($token);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Authentication failed',
            ], 401);
        }

        // Sync user to local DB and get the local user record
        $localUser = null;
        if (isset($result['user'])) {
            $localUser = $this->userSync->syncFromAuthServicePayload($result['user']);
        }

        // Enrich response with BRTHub-specific data
        $brthubData = [];
        if ($localUser) {
            $position = $localUser->employeeProfile?->position;

            // Approver adalah posisi (bukan role): FE butuh hierarchy_level
            // untuk menentukan apakah user ini berhak membuka /approver
            $brthubData = [
                'roles' => $localUser->getActiveRoles(),
                'employee_profile' => $localUser->employeeProfile ? [
                    'id' => $localUser->employeeProfile->id,
                    'department_id' => $localUser->employeeProfile->department_id,
                    'position_id' => $localUser->employeeProfile->position_id,
                    'position_name' => $position?->name,
                    'hierarchy_level' => $position?->hierarchy_level,
                ] : null,
            ];
        }

        return response()->json(array_merge($result, ['brthub' => $brthubData]));
    }

    /**
     * Update the reporter profile (full_name, phone_number, email) in the IdP.
     *
     * The IdP allows this exactly once: after the profile is complete it answers
     * 409 PROFILE_LOCKED and only an admin may change the data.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        if (! empty($validated['phone_number']) && ! PhoneNumber::isValid($validated['phone_number'])) {
            return response()->json([
                'success' => false,
                'message' => 'Format nomor telepon tidak valid. Gunakan format +62xxxxxxxxxx.',
            ], 422);
        }

        // Profile can be updated continuously.

        if (empty($validated['phone_number']) && empty($validated['email'])) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor telepon atau email wajib diisi.',
            ], 422);
        }

        $payload = array_filter([
            'full_name' => $validated['full_name'],
            'phone_number' => $validated['phone_number'] ?? null,
            'email' => $validated['email'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $idpResult = $this->authService->updateProfile($request->bearerToken(), $payload);

        if (! ($idpResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $idpResult['error'] ?? 'Gagal menyimpan data pelapor',
            ], $idpResult['status'] ?? 422);
        }

        // Kunci lokal hanya bila IdP melaporkan profil lengkap (nama+telepon+email),
        // supaya tidak terkunci permanen ketika email masih kosong.
        $idpProfileComplete = (bool) ($idpResult['data']['profile_complete'] ?? false);

        $user->update([
            'full_name' => $validated['full_name'],
            'phone_number' => PhoneNumber::normalize($validated['phone_number'] ?? null) ?? $user->phone_number,
            'email' => $validated['email'] ?? $user->email,
            'address' => $validated['address'] ?? $user->address,
            'profile_completed_at' => $idpProfileComplete ? now() : $user->profile_completed_at,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data pelapor berhasil disimpan',
            'data' => $this->userSync->localProfilePayload($user->refresh()) + [
                'profile_complete' => $idpProfileComplete,
            ],
        ]);
    }

    /**
     * Check if the authenticated user has an employee or customer profile in BRTHub.
     * Returns whether they need to go through the type selection flow.
     */
    public function identityStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $employeeProfile = $user->employeeProfile;
        $customer = $user->customer;

        return response()->json([
            'success' => true,
            'data' => [
                'is_defined' => (bool) ($employeeProfile || $customer),
                'profile_complete' => (bool) $user->profile_completed_at,
                'type' => $employeeProfile ? 'EMPLOYEE' : ($customer ? 'CUSTOMER' : null),
                'identity' => $this->userSync->localProfilePayload($user),
                'employee_profile' => $employeeProfile ? [
                    'id' => $employeeProfile->id,
                    'department_id' => $employeeProfile->department_id,
                    'position_id' => $employeeProfile->position_id,
                ] : null,
            ],
        ]);
    }

    /**
     * Sync user data from Auth Service to local DB.
     *
     * @param  array<string, mixed>  $userData
     */
    protected function syncUserFromAuthService(array $userData): void
    {
        $this->userSync->syncFromAuthServicePayload($userData);
    }
}
