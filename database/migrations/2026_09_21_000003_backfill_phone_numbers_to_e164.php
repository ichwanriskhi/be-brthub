<?php

use App\Services\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-shot backfill: every stored phone number is rewritten into the
     * canonical +62 E.164 format so lookups always match IdP-synced rows.
     * Duplicates caused by divergent formats are resolved by keeping the
     * first row and clearing the number on the rest.
     */
    public function up(): void
    {
        $users = DB::table('users')->select('id', 'phone_number')->get();

        $seen = [];
        $updates = [];
        $cleared = [];

        foreach ($users as $user) {
            $normalized = PhoneNumber::normalize($user->phone_number);

            if ($normalized === null) {
                continue;
            }

            if ($normalized === $user->phone_number && ! isset($seen[$normalized])) {
                $seen[$normalized] = $user->id;

                continue;
            }

            if (isset($seen[$normalized])) {
                $cleared[] = $user->id;

                continue;
            }

            $seen[$normalized] = $user->id;
            $updates[$user->id] = $normalized;
        }

        foreach ($updates as $id => $phone) {
            DB::table('users')->where('id', $id)->update(['phone_number' => $phone]);
        }

        foreach ($cleared as $id) {
            DB::table('users')->where('id', $id)->update(['phone_number' => null]);
        }
    }

    public function down(): void
    {
        // Data migration: no reversible transformation (raw formats are lost).
    }
};
