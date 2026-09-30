<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthServiceClient
{
    protected string $baseUrl;

    protected string $clientId;

    protected string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('auth_service.url', 'http://localhost:8000'), '/');
        $this->clientId = config('auth_service.client_id');
        $this->clientSecret = config('auth_service.client_secret');
    }

    /**
     * Get current authenticated user from Auth Service.
     * Requires bearer token.
     */
    public function getUser(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$this->baseUrl}/api/auth/me");

        if (! $response->successful()) {
            return ['success' => false, 'error' => $response->json('message', 'Authentication failed')];
        }

        $data = $response->json();
        // Normalize response: auth-service returns {success, data: {user...}}
        if (isset($data['data'])) {
            return ['success' => true, 'user' => $data['data']];
        }

        return ['success' => false, 'error' => 'Invalid response format'];
    }

    /**
     * Detect if identifier is email or phone and route to correct OTP endpoint.
     */
    protected function isEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normalize a phone number into the canonical +62 format used by the IdP.
     */
    protected function normalizePhone(string $phone): string
    {
        return PhoneNumber::normalize($phone) ?? '';
    }

    /**
     * Request OTP (auto-detect email vs phone).
     */
    public function requestOtpAuto(string $identifier, string $action): array
    {
        if ($this->isEmail($identifier)) {
            $response = Http::post("{$this->baseUrl}/api/auth/email-otp/request", [
                'action' => $action,
                'email' => strtolower(trim($identifier)),
            ]);
        } else {
            $response = Http::post("{$this->baseUrl}/api/auth/otp/request", [
                'action' => $action,
                'phone_number' => $this->normalizePhone($identifier),
            ]);
        }

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => $response->json('message', 'Request failed'),
        ];
    }

    /**
     * Verify OTP (auto-detect email vs phone).
     */
    public function verifyOtpAuto(string $identifier, string $code, string $action): array
    {
        if ($this->isEmail($identifier)) {
            $response = Http::post("{$this->baseUrl}/api/auth/email-otp/verify", [
                'action' => $action,
                'email' => strtolower(trim($identifier)),
                'otp' => $code,
            ]);
        } else {
            $response = Http::post("{$this->baseUrl}/api/auth/otp/verify", [
                'action' => $action,
                'phone_number' => $this->normalizePhone($identifier),
                'otp' => $code,
            ]);
        }

        if ($response->successful()) {
            $data = $response->json();

            return $data ?? ['success' => true, 'data' => []];
        }

        return [
            'success' => false,
            'error' => $response->json('message', 'Verification failed'),
        ];
    }

    /**
     * Redirect URL for Google OAuth.
     */
    public function getGoogleRedirectUrl(): string
    {
        return "{$this->baseUrl}/api/auth/google/redirect";
    }

    /**
     * Exchange auth code for token (called on callback).
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::post("{$this->baseUrl}/api/auth/google/callback", [
            'code' => $code,
        ]);

        return $response->json([
            'success' => false,
            'access_token' => null,
            'user' => null,
        ]);
    }

    /**
     * Look up an existing IdP user by phone number (E.164) and/or email.
     * Used so an employee reporting on behalf of a customer never duplicates a user.
     *
     * @return array{success: bool, user?: array<string, mixed>, error?: string}
     */
    public function findUserByPhoneOrEmail(?string $phoneNumber = null, ?string $email = null): array
    {
        if (! $phoneNumber && ! $email) {
            return ['success' => false, 'error' => 'not_found'];
        }

        $token = $this->getClientCredentialsToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Failed to get client credentials token'];
        }

        $query = array_filter([
            'phone' => $phoneNumber ? PhoneNumber::normalize($phoneNumber) : null,
            'email' => $email ? strtolower(trim($email)) : null,
        ]);

        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$this->baseUrl}/api/v1/admin/users", $query);

        if ($response->status() === 404) {
            return ['success' => false, 'error' => 'not_found'];
        }

        if (! $response->successful()) {
            return ['success' => false, 'error' => $response->json('message', 'User lookup failed')];
        }

        return ['success' => true, 'user' => $response->json('data', [])];
    }

    /**
     * Persist the reporter profile (full_name, phone_number, email) to the IdP.
     * The IdP enforces the one-time rule and answers 409 once the profile is locked.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{success: bool, data?: array<string, mixed>, status?: int, error?: string}
     */
    public function updateProfile(string $token, array $attributes): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->patch("{$this->baseUrl}/api/auth/me", $attributes);

        if (! $response->successful()) {
            return [
                'success' => false,
                'status' => $response->status(),
                'error' => $response->json('message', 'Failed to update profile'),
            ];
        }

        return $response->json();
    }

    /**
     * Get client credentials token for admin API calls.
     */
    protected function getClientCredentialsToken(): ?string
    {
        $cacheKey = "auth_service_client_token_{$this->clientId}";

        return Cache::remember($cacheKey, 55, function () {
            $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'admin',
            ]);

            if (! $response->successful()) {
                Log::error('Failed to get client credentials token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    /**
     * Setup password link for user (admin endpoint).
     */
    public function createSetupPasswordLink(string $userId): array
    {
        $token = $this->getClientCredentialsToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Failed to get client credentials token'];
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/api/v1/admin/apps/{$this->clientId}/users/{$userId}/setup-password-link");

        return $response->json([
            'success' => false,
            'error' => 'Failed to create setup password link',
        ]);
    }

    /**
     * Reset password link for user (admin endpoint).
     */
    public function createResetPasswordLink(string $userId): array
    {
        $token = $this->getClientCredentialsToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Failed to get client credentials token'];
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/api/v1/admin/apps/{$this->clientId}/users/{$userId}/reset-password-link");

        return $response->json([
            'success' => false,
            'error' => 'Failed to create reset password link',
        ]);
    }

    /**
     * Refresh access token.
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = Http::asForm()->post("{$this->baseUrl}/api/auth/token/refresh", [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        return $response->json([
            'success' => false,
            'error' => 'Token refresh failed',
        ]);
    }

    /**
     * Revoke token (logout).
     */
    public function revokeToken(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/api/auth/logout");

        return $response->json([
            'success' => false,
            'error' => 'Logout failed',
        ]);
    }
}
