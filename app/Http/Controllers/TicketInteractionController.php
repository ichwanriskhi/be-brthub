<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketInteraction;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\Request;

/**
 * Percakapan (obrolan) tiket antar pihak yang terlibat.
 *
 * Aturan visibilitas pesan:
 * - Pelapor (reporter) hanya melihat pesan publik (is_internal = false) dan
 *   hanya dapat mengirim pesan publik. "Pelapor" di sini adalah posisi
 *   (tiket dibuat atas namanya), bukan role — jadi tidak Eliminate role staff
 *   yang ia miliki. Sesi portal pelapor juga selalu diperlakukan sebagai
 *   pelapor, karena portal itu adalah tampilan pelanggan.
 * - Reviewer & handler dapat memilih pesan mereka publik atau internal
 *   (flag is_internal dari klien).
 * - Unit, approver, dan admin hanya dapat mengirim pesan internal, tetapi tetap
 *   dapat membaca SELURUH percakapan (publik maupun internal).
 *
 * Catatan urutan: bila satu orang memegang beberapa role sekaligus (mis.
 * reviewer sekaligus unit), yang diperiksa lebih dulu adalah reviewer/handler,
 * sehingga ia tetap mendapat hak memilih. Mengurutkan sebaliknya membuat
 * pesan yang ia kirim diam-diam dipaksa internal.
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
     * Apakah request ini datang dari portal pelapor (tampilan pelanggan).
     *
     * Portal pelapor dan portal staf memakai token yang sama — `fetch-wrapper`
     * bahkan menyalin token staf ke `auth_token` — jadi backend tidak bisa
     * membedakan keduanya dari dokumen token. Satu-satunya penanda adalah
     * query/field dari klien, sama seperti `?mine=true` pada
     * `TicketController::index()` untuk "Laporan Saya".
     *
     * Penanda ini aman karena sifatnya MONOTON: dia hanya bisa mengurangi yang
     * terlihat, tidak pernah menambah.
     *
     * - Staf yang mengirim `surface=reporter` justru mendapat lebih sedikit.
     * - Pelapor yang memalsukan `surface=staff` tetap ditolak `canSeeInternal()`,
     *   karena ia tidak memegang role internal dan tidak berposisi approver.
     */
    private function isReporterSurface(Request $request): bool
    {
        return $request->input('surface') === 'reporter';
    }

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
            ->with([
                'actor:id,auth_service_uuid,full_name',
                // `roleLabel()` memanggil `hasRole()` sampai empat kali per pesan
                // dan `employeeProfile` untuk pengecekan approver. Tanpa eager-load
                // dua relasi ini, tiap pesan menambah beberapa query.
                'actor.roles:id,name',
                'actor.employeeProfile:id,user_id,position_id',
                // `roleLabel()` membaca `ticket.reporter_user_id` dan
                // `ticket.approval_type`. Tanpa eager-load ini relasi tersebut
                // di-query ulang untuk tiap pesan (N+1).
                'ticket:id,reporter_user_id,approval_type',
                // Kolom terpilih: `payload_json`/`response_json` tidak ada di
                // sini, tapi `file_path` wajib agar accessor `url` bisa dihitung.
                'attachments:id,attachable_type,attachable_id,file_name,file_path,mime_type,file_size',
            ])
            ->where('ticket_id', $ticket->id)
            ->where('interaction_type', 'CHAT');

        // Portal pelapor adalah tampilan pelanggan: hanya pesan publik, apa pun
        // role staf yang mungkin dipegang akun yang sedang login. Dicek sebelum
        // `canSeeInternal()` karena portal ini tidak boleh melihat internal
        // meski pun role-nya memenuhi syarat.
        if ($this->isReporterSurface($request) || ! $this->canSeeInternal($user, $ticket)) {
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
     * berhak membuat pesan internal (pelapor) atau hanya boleh internal
     * (approver/unit/admin), dan juga diabaikan total di portal pelapor.
     */
    public function store(Request $request, string $ticketId)
    {
        $ticket = $this->resolveTicket($ticketId);
        $user = $request->user();

        $this->authorizeRead($user, $ticket);

        $validated = $request->validate([
            // `required_without:attachments` sudah sejak awal mengizinkan
            // pesan tanpa teks selama ada lampiran.
            'content' => 'required_without:attachments|string|max:2000',
            'is_internal' => 'boolean',
            'attachments' => 'nullable|array|max:5',
            // ZIP sengaja TIDAK ada di daftar ini. `FileDropzone` di frontend
            // sempat menawarkan zip padahal backend tidak pernah mengizinkan
            // nya - jadi user bisa memilih file lalu ditolak API.
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,gif,mp4,webm,pdf,doc,docx,txt|max:5120',
        ]);

        $requestedInternal = (bool) ($validated['is_internal'] ?? false);

        // Di portal pelapor, `is_internal` dari klien diabaikan TOTAL — tidak
        // sekadar ditimpa. Klien yang dimodifikasi tidak boleh bisa mengirim
        // pesan internal dari sini, meskipun ia memegang role staf.
        $isInternal = $this->isReporterSurface($request)
            ? false
            : $this->resolveInternalFlag($user, $ticket, $requestedInternal);

        $interaction = TicketInteraction::create([
            'ticket_id' => $ticket->id,
            'actor_user_id' => $user->id,
            'interaction_type' => 'CHAT',
            'content' => $validated['content'] ?? '',
            'is_internal' => $isInternal,
            'metadata' => null,
        ]);

        foreach ($request->file('attachments', []) as $file) {
            if (! $file) {
                continue;
            }

            $path = $file->store('attachments/interactions', 'public');

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'attachable_type' => TicketInteraction::class,
                'attachable_id' => $interaction->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_user_id' => $user->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pesan terkirim.',
            'data' => $this->format(
                $interaction->load([
                    'actor:id,auth_service_uuid,full_name',
                    'actor.roles:id,name',
                    'actor.employeeProfile:id,user_id,position_id',
                    'ticket:id,reporter_user_id,approval_type',
                    'attachments',
                ]),
                $user,
            ),
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

        // `canApproveTicket` (bukan `approvalService->canApprove` langsung) karena
        // user yang bukan owner dan bukan staf — misalnya pelapor lain yang
        // salah mengetik ID tiket — tidak punya `employee_profile`. Tanpa
        // null-guard, `employeeProfile` null masuk ke parameter non-nullable dan
        // exception-nya muncul sebagai 500, bukan 403 yang seharusnya.
        if ($isOwner || $isStaff || $this->canApproveTicket($user, $ticket)) {
            return;
        }

        abort(403, 'Anda tidak berhak melihat percakapan tiket ini.');
    }

    /**
     * Apakah user ini berhak menjadi approver untuk tiket ini (berdasarkan posisi).
     *
     * Approver adalah posisi, bukan role. `employeeProfile` nullable — pelapor
     * (customer) tidak punya profil pegawai sama sekali — jadi HARUS diperiksa
     * null-nya sebelum diteruskan ke `ApprovalService::canApprove()` yang
     * parameternya non-nullable. Tanpa guard ini, satu pesan dari pelapor
     * sudah cukup untuk mengembalikan 500.
     *
     * @param  ?User  $user  null aman — dianggap tidak berhak.
     * @param  ?Ticket  $ticket  null aman — dianggap tidak berhak.
     *
     * Mengikuti pola yang sama di `TicketController::canUserApprove()`.
     */
    private function canApproveTicket(?User $user, ?Ticket $ticket): bool
    {
        $employee = $user?->employeeProfile;

        return $employee !== null
            && $ticket !== null
            && $this->approvalService->canApprove($employee, $ticket);
    }

    /**
     * Pesan internal hanya boleh dilihat oleh staf internal & approver.
     *
     * Kepemilikan tiket TIDAK lagi menjadi syarat untuk melihat pesan internal:
     * role lebih diutamakan daripada posisi sebagai pelapor. Pegawai yang
     * membuat tiket sendiri tetap dapat mengikuti diskusi internal tentang
     * tiketnya, selama ia memegang role internal atau berposisi approver.
     *
     * Pelapor biasa tetap aman — ia tidak memegang role internal mana pun dan
     * tidak punya `employee_profile`, jadi jatuh ke `false`.
     */
    private function canSeeInternal(?User $user, Ticket $ticket): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole('admin')
            || $user->hasRole('handler')
            || $user->hasRole('reviewer')
            || $user->hasRole('unit')) {
            return true;
        }

        return $this->canApproveTicket($user, $ticket);
    }

    /**
     * Ubah flag internal sesuai aturan peran pengirim.
     *
     * Urutan pemeriksaan menentukan hak memilih, dan itu disengaja:
     * reviewer/handler diperiksa LEBIH DAHULU. Bila tidak, seorang pegawai yang
     * memegang `unit` sekaligus `reviewer` akan tersedak oleh cabang `unit` dan
     * pesan yang ia kirim diam-diam dipaksa internal — padahal di sisi klien
     * ia tampil punya pilihan publik.
     *
     * Kepemilikan tiket tidak lagi diprioritaskan: sama seperti `canSeeInternal`,
     * role mendahului posisi sebagai pelapor.
     */
    private function resolveInternalFlag(User $user, Ticket $ticket, bool $requested): bool
    {
        // Reviewer & handler: bebas memilih publik atau internal.
        if ($user->hasRole('reviewer') || $user->hasRole('handler')) {
            return $requested;
        }

        // Unit / admin / approver: pesan wajib internal.
        if ($user->hasRole('unit') || $user->hasRole('admin') || $this->canApproveTicket($user, $ticket)) {
            return true;
        }

        // everyone lain (termasuk pelapor): pesan wajib publik.
        return false;
    }

    /**
     * Format pesan untuk respons API.
     *
     * `is_ticket_reporter` menyatakan fakta bahwa pengirim pesan ini adalah
     * pemilik tiket. Dihitung dari `ticket.reporter_user_id`, BUKAN dari klaim
     * klien, jadi frontend boleh menampilkannya tanpa perlu mempercayainya.
     *
     * Frontend memakainya untuk menampilkan dua fakta sekaligus — role pegawai
     * dan hubungan sebagai pelapor — karena keduanya sama-sama benar untuk
     * orang yang memegang banyak role sekaligus. Label akhir tidak boleh
     * bergantung pada portal mana yang membaca: satu pesan yang sama akan
     * tampak berbeda di layar staf dan layar pelapor.
     */
    private function format(TicketInteraction $i, ?User $user): array
    {
        $actor = $i->actor;
        $isDeleted = $i->deleted_at !== null;

        return [
            'id' => (string) $i->id,
            'ticket_id' => (string) $i->ticket_id,
            'sender_user_id' => $actor?->auth_service_uuid ?? (string) $i->actor_user_id,
            'sender_name' => $actor?->full_name ?? 'Pengguna',
            'sender_role' => $this->roleLabel($i),
            'is_ticket_reporter' => $actor !== null
                && $i->ticket !== null
                && $i->ticket->reporter_user_id === $actor->id,
            'is_internal' => (bool) $i->is_internal,
            'is_deleted' => $isDeleted,
            'message' => $i->content ?? '',
            // Lampiran disembunyikan saat pesan di-soft-delete supaya benar-benar
            // hilang dari tampilan. Berkas di storage tetap ada untuk audit.
            'attachments' => $isDeleted
                ? []
                : $i->attachments->map(fn (TicketAttachment $a) => [
                    'id' => (string) $a->id,
                    'name' => $a->file_name,
                    'size' => (string) $a->file_size,
                    'mime_type' => $a->mime_type,
                    'url' => $a->url,
                ])->values()->all(),
            'created_at' => $i->created_at?->toIso8601String(),
            'can_delete' => $i->actor_user_id === $user?->id || (bool) $user?->hasRole('admin'),
        ];
    }

    /**
     * Label peran untuk tampilan FE.
     *
     * Ditentukan dari role AKTOR-nya, bukan dari posisinya sebagai pelapor dan
     * bukan dari peran viewer. Dua alasan:
     *
     * - Dulu pemilik tiket selalu dilabeli 'Pelapor' lebih dulu. Sekarang role
     *   mendahului kepemilikan, jadi pesan yang dikirim pegawai ber-role reviewer
     *   harus tetap tampil sebagai 'Reviewer' walau tiket itu miliknya sendiri.
     * - Pemeriksaan approver dulu memakai posisinya VIEWER untuk melabeli AKTOR,
     *   sehingga begitu ada satu approver membuka percakapan, semua pesan orang
     *   lain ikut berlabel 'Approver'.
     */
    private function roleLabel(TicketInteraction $i): string
    {
        $actor = $i->actor;
        if ($actor === null) {
            return 'Pengguna';
        }

        // `hasRole()` melakukan satu query per panggilan, dan di sini dipanggil
        // sampai empat kali untuk tiap pesan. `roles` sudah di-eager-load, jadi
        // cukup dibaca dari memori.
        $roles = $actor->roles->pluck('name')->all();

        if (in_array('admin', $roles, true)) {
            return 'Admin';
        }
        if (in_array('reviewer', $roles, true)) {
            return 'Reviewer';
        }
        if (in_array('handler', $roles, true)) {
            return 'Handler';
        }
        if (in_array('unit', $roles, true)) {
            return 'Unit';
        }

        if ($this->canApproveTicket($actor, $i->ticket)) {
            return 'Approver';
        }

        // Tidak memegang role staff, tapi tetap penulis di tiket ini → pelapor.
        if ($i->ticket !== null && $i->ticket->reporter_user_id === $actor->id) {
            return 'Pelapor';
        }

        return 'Staf';
    }
}
