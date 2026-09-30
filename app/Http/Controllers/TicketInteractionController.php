<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketInteraction;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\Request;

/**
 * Percakapan (obrolan) tiket antar pihak yang terlibat.
 *
 * Aturan visibilitas pesan:
 * - Reporter hanya melihat pesan publik (is_internal = false), tidak boleh
 *   membuat pesan internal.
 * - Reviewer & handler dapat memilih pesan mereka publik atau internal
 *   (flag is_internal dari klien).
 * - Approver, unit, dan admin hanya dapat mengirim pesan internal.
 *
 * Pesan dapat dihapus (soft delete) oleh pengirimnya; admin dapat menghapus
 * pesan siapa pun untuk moderasi. Baris tetap disimpan untuk audit trail.
 */
class TicketInteractionController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    /**
     * Daftar pesan sebuah tiket, sesuai hak pengirim pesan (soft-deleted
     * disembunyikan kecuali untuk admin).
     */
    public function index(Request $request, string $ticketId)
    {
        $ticket = $this->resolveTicket($ticketId);
        $user = $request->user();

        $this->authorizeRead($user, $ticket);

        $query = TicketInteraction::query()
            ->with('actor:id,auth_service_uuid,full_name')
            ->where('ticket_id', $ticket->id)
            ->where('interaction_type', 'CHAT');

        if (! $this->canSeeInternal($user, $ticket)) {
            $query->where('is_internal', false);
        }

        // Admin melihat pesan terhapus; pihak lain tidak.
        if (! $user || ! $user->hasRole('admin')) {
            $query->whereNull('deleted_at');
        }

        $messages = $query->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn (TicketInteraction $i) => $this->format($i, $user)),
        ]);
    }

    /**
     * Kirim pesan baru. Flag `is_internal` diabaikan bila pengirim tidak
     * berhak membuat pesan internal (reporter) atau hanya boleh internal
     * (approver/unit/admin).
     */
    public function store(Request $request, string $ticketId)
    {
        $ticket = $this->resolveTicket($ticketId);
        $user = $request->user();

        $this->authorizeRead($user, $ticket);

        $validated = $request->validate([
            'content' => 'required_without:attachments|string|max:2000',
            'is_internal' => 'boolean',
        ]);

        $requestedInternal = (bool) ($validated['is_internal'] ?? false);
        $isInternal = $this->resolveInternalFlag($user, $ticket, $requestedInternal);

        $interaction = TicketInteraction::create([
            'ticket_id' => $ticket->id,
            'actor_user_id' => $user->id,
            'interaction_type' => 'CHAT',
            'content' => $validated['content'] ?? '',
            'is_internal' => $isInternal,
            'metadata' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pesan terkirim.',
            'data' => $this->format($interaction->load('actor:id,auth_service_uuid,full_name'), $user),
        ], 201);
    }

    /**
     * Hapus (soft delete) pesan. Pengirim boleh menghapus pesannya sendiri;
     * admin boleh menghapus pesan siapa pun.
     */
    public function destroy(Request $request, string $ticketId, string $interactionId)
    {
        $ticket = $this->resolveTicket($ticketId);
        $user = $request->user();

        $interaction = TicketInteraction::query()
            ->where('ticket_id', $ticket->id)
            ->where('interaction_type', 'CHAT')
            ->findOrFail($interactionId);

        $canDelete = $interaction->actor_user_id === $user->id || $user->hasRole('admin');

        if (! $canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'Anda hanya dapat menghapus pesan Anda sendiri.',
            ], 403);
        }

        $interaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pesan dihapus.',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveTicket(string $ticketId): Ticket
    {
        $query = Ticket::query();

        if (is_numeric($ticketId)) {
            return $query->findOrFail($ticketId);
        }

        return $query->where('ticket_no', $ticketId)->firstOrFail();
    }

    /**
     * Hanya pemilik tiket, staf internal, atau approver tiket yang boleh
     * membaca percakapannya.
     */
    private function authorizeRead(?User $user, Ticket $ticket): void
    {
        if ($user === null) {
            abort(401, 'Sesi tidak ditemukan.');
        }

        $isOwner = $ticket->reporter_user_id === $user->id;
        $isStaff = $user->hasRole('admin')
            || $user->hasRole('handler')
            || $user->hasRole('reviewer')
            || $user->hasRole('unit');

        if ($isOwner || $isStaff || $this->approvalService->canApprove($user->employeeProfile, $ticket)) {
            return;
        }

        abort(403, 'Anda tidak berhak melihat percakapan tiket ini.');
    }

    /**
     * Pesan internal hanya boleh dilihat oleh staf internal & approver.
     *
     * Catatan: pemilik tiket selalu diperlakukan sebagai reporter, meskipun
     * ia juga memegang role staff (mis. reviewer). Tanpa pengecualian ini,
     * seorang pegawai yang melapor sendiri bisa melihat pesan internalnya.
     */
    private function canSeeInternal(?User $user, Ticket $ticket): bool
    {
        if ($user === null) {
            return false;
        }

        if ($ticket->reporter_user_id === $user->id) {
            return false;
        }

        if ($user->hasRole('admin')
            || $user->hasRole('handler')
            || $user->hasRole('reviewer')
            || $user->hasRole('unit')) {
            return true;
        }

        return $this->approvalService->canApprove($user->employeeProfile, $ticket);
    }

    /**
     * Ubah flag internal sesuai aturan peran pengirim.
     *
     * Pemilik tiket selalu dianggap reporter (pesan wajib publik), meskipun
     * ia memegang role reviewer/handler — argumennya sama dengan canSeeInternal.
     */
    private function resolveInternalFlag(User $user, Ticket $ticket, bool $requested): bool
    {
        // Pemilik tiket sebagai reporter: pesan wajib publik.
        if ($ticket->reporter_user_id === $user->id) {
            return false;
        }

        // Approver / unit / admin: pesan wajib internal.
        $forceInternal = $user->hasRole('admin')
            || $user->hasRole('unit')
            || $this->approvalService->canApprove($user->employeeProfile, $ticket);

        if ($forceInternal) {
            return true;
        }

        // Reviewer & handler: bebas memilih publik atau internal.
        $isStaffWithChoice = $user->hasRole('handler') || $user->hasRole('reviewer');

        return $isStaffWithChoice ? $requested : false;
    }

    /**
     * Format pesan untuk respons API.
     */
    private function format(TicketInteraction $i, ?User $user): array
    {
        $actor = $i->actor;

        return [
            'id' => (string) $i->id,
            'ticket_id' => (string) $i->ticket_id,
            'sender_user_id' => $actor?->auth_service_uuid ?? (string) $i->actor_user_id,
            'sender_name' => $actor?->full_name ?? 'Pengguna',
            'sender_role' => $this->roleLabel($user, $i),
            'is_internal' => (bool) $i->is_internal,
            'is_deleted' => $i->deleted_at !== null,
            'message' => $i->content ?? '',
            'created_at' => $i->created_at?->toIso8601String(),
            'can_delete' => $i->actor_user_id === $user?->id || (bool) $user?->hasRole('admin'),
        ];
    }

    /**
     * Label peran untuk tampilan FE, sesuai aturan:
     * reporter → 'Pelapor', staff → nama peran, approver → 'Approver'.
     */
    private function roleLabel(?User $viewer, TicketInteraction $i): string
    {
        $actor = $i->actor;
        if ($actor === null) {
            return 'Pengguna';
        }

        $isReporter = $i->ticket->reporter_user_id === $actor->id;

        if ($isReporter) {
            return 'Pelapor';
        }

        if ($actor->hasRole('admin')) {
            return 'Admin';
        }
        if ($actor->hasRole('reviewer')) {
            return 'Reviewer';
        }
        if ($actor->hasRole('handler')) {
            return 'Handler';
        }
        if ($actor->hasRole('unit')) {
            return 'Unit';
        }

        if ($viewer && $this->approvalService->canApprove($viewer->employeeProfile, $i->ticket)) {
            return 'Approver';
        }

        return 'Staf';
    }
}
