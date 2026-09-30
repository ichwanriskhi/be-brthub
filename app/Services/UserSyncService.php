<?php

namespace App\Services;

use App\Models\User;

/**
 * Single entry point for mirroring Auth Service (IdP) users into the local
 * users table. Used by the API middleware, the OTP proxy and the ticket flow,
 * so a user is never duplicated when the same person already exists locally.
 */
class UserSyncService
{
    /**
     * Create or refresh the local user row for an Auth Service user payload.
     *
     * @param  array<string, mixed>  $authUser  Payload from `/api/auth/me` or the OTP verify response.
     */
    public function syncFromAuthServicePayload(array $authUser): User
    {
        $uuid = $authUser['uuid'] ?? $authUser['id'] ?? null;

        if (! $uuid) {
            throw new \InvalidArgumentException('Auth Service user payload does not contain a uuid/id.');
        }

        $email = isset($authUser['email']) && $authUser['email'] !== ''
            ? strtolower(trim((string) $authUser['email']))
            : null;

        $attributes = [
            'full_name' => $authUser['full_name'] ?? $authUser['name'] ?? '',
            'email' => $email,
            'phone_number' => PhoneNumber::normalize($authUser['phone_number'] ?? null),
            'is_active' => $authUser['is_active'] ?? true,
            'source' => 'auth_service',
            'last_synced_at' => now(),
        ];

        // Only touch the lock when the IdP actually reports it, so a locally
        // known completion is never wiped by a partial payload.
        if (array_key_exists('profile_completed_at', $authUser)) {
            $attributes['profile_completed_at'] = $authUser['profile_completed_at'];
        }

        $user = User::where('auth_service_uuid', $uuid)->first()
            ?? $this->findUnlinkedLocalUser($attributes['phone_number'], $attributes['email']);

        if ($user) {
            $user->update($attributes + ['auth_service_uuid' => $uuid]);

            return $user->refresh();
        }

        return User::create($attributes + ['auth_service_uuid' => $uuid]);
    }

    /**
     * Snapshot the current local profile into the shape returned by the IdP,
     * so the reporter step can be rendered read-only once the profile is complete.
     *
     * @return array<string, mixed>
     */
    public function localProfilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->auth_service_uuid,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'address' => $user->address,
        ];
    }

    /**
     * Find a locally created (walk-in) user that was never linked to the IdP yet.
     * It is "adopted" instead of creating a duplicate row for the same person.
     */
    protected function findUnlinkedLocalUser(?string $phoneNumber, ?string $email): ?User
    {
        if (! $phoneNumber && ! $email) {
            return null;
        }

        if ($phoneNumber) {
            $existing = User::whereNull('auth_service_uuid')
                ->where('phone_number', $phoneNumber)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($email) {
            return User::whereNull('auth_service_uuid')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        return null;
    }
}
