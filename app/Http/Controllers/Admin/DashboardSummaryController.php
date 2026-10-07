<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketStatus;
use App\Models\WansisReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardSummaryController extends Controller
{
    /**
     * GET /api/admin/dashboard-summary
     *
     * Satu request untuk ringkasan dasbor admin:
     * - kpi: hitung per kebutuhan dasbor (aktif, perlu perhatian, review,
     *   dikerjakan, closed, rejected, pegawai, pelanggan)
     * - funnel: jumlah tiket per status (urut sort_order)
     * - workload: beban per departemen dari assignment aktif
     * - trends: agregat harian per kategori & produk
     * - duration: lama penyelesaian tiket yang baru tertutup
     * - wansis: jumlah laporan WANSIS menurut status pengiriman
     * - oldest_open: 5 tiket OPEN tertua (kandidat "Perlu Perhatian")
     *
     * Definisi "selesai": is_terminal = 1 ATAU closed_at terisi
     * (konsisten dengan CustomerController).
     */
    public function index(): JsonResponse
    {
        // ── Hitung tiket per status (sekali query) ──────────────────
        $perStatus = Ticket::query()
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.status_id')
            ->selectRaw('ticket_statuses.code AS code, COUNT(*) AS total')
            ->groupBy('ticket_statuses.code')
            ->pluck('total', 'code');

        // Aktif = non-terminal & belum closed (definisi yang sama dengan statistik customer)
        $active = (int) Ticket::query()
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.status_id')
            ->where('ticket_statuses.is_terminal', 0)
            ->whereNull('tickets.closed_at')
            ->count();

        $kpi = [
            'active' => $active,
            'need_attention' => (int) ($perStatus['OPEN'] ?? 0)
                + (int) ($perStatus['REWORK_REQUIRED'] ?? 0),
            'pending_review' => (int) ($perStatus['PENDING_REVIEW'] ?? 0),
            'in_progress' => (int) ($perStatus['IN_PROGRESS'] ?? 0),
            'closed' => (int) ($perStatus['CLOSED'] ?? 0),
            'rejected' => (int) ($perStatus['REJECTED'] ?? 0),
            'employees' => EmployeeProfile::count(),
            'customers' => Customer::count(),
        ];

        // ── Funnel per status ───────────────────────────────────────
        $funnel = TicketStatus::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_terminal', 'sort_order'])
            ->map(fn (TicketStatus $status) => [
                'id' => (int) $status->id,
                'code' => $status->code,
                'name' => $status->name,
                'is_terminal' => (bool) $status->is_terminal,
                'count' => (int) ($perStatus[$status->code] ?? 0),
            ])
            ->values();

        // ── Workload per departemen (assignment aktif) ──────────────
        $departmentNames = Department::query()
            ->pluck('name', 'id');

        $workload = TicketAssignment::query()
            ->selectRaw('assigned_to_department_id, COUNT(*) AS total')
            ->where('is_active', 1)
            ->whereNull('unassigned_at')
            ->groupBy('assigned_to_department_id')
            ->get()
            ->map(function (object $row) use ($departmentNames) {
                $departmentId = $row->assigned_to_department_id !== null
                    ? (int) $row->assigned_to_department_id
                    : null;

                return [
                    'department_id' => $departmentId,
                    'department' => $departmentId !== null
                        ? ($departmentNames[$departmentId] ?? 'Tanpa Departemen')
                        : 'Belum Ditugaskan',
                    'count' => (int) $row->total,
                ];
            })
            ->sortByDesc('count')
            ->values();

        // ── Tren harian (agregat per kategori & produk) ─────────────
        // Jendela 1 tahun, bukan 90 hari: frontend aggregating sendiri dari
        // baris harian ini, dan tab rentangnya mencapai `1y`. Memotong di 90
        // hari akan membuat tab 6 Bulan / 1 Tahun kosong tanpa penjelasan.
        $since = Carbon::now()->subYear()->startOfDay();

        $categoryTrend = Ticket::query()
            ->where('tickets.created_at', '>=', $since)
            ->selectRaw('DATE(tickets.created_at) AS day, tickets.category_id AS category_id, COUNT(*) AS total')
            ->groupBy(DB::raw('DATE(tickets.created_at)'), 'tickets.category_id')
            ->orderBy('day')
            ->get()
            ->map(fn (object $row) => [
                'date' => (string) $row->day,
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'count' => (int) $row->total,
            ])
            ->values();

        // Tren lini produk = kode grup WANSIS (bukan id lokal; master tunggal
        // ada di SAP). Nama grup di-resolve FE lewat cache item-groups.
        $productTrend = Ticket::query()
            ->join('ticket_vehicle_details', 'ticket_vehicle_details.ticket_id', '=', 'tickets.id')
            ->where('tickets.created_at', '>=', $since)
            ->whereNotNull('ticket_vehicle_details.group_code')
            ->selectRaw('DATE(tickets.created_at) AS day, ticket_vehicle_details.group_code AS group_code, COUNT(*) AS total')
            ->groupBy(DB::raw('DATE(tickets.created_at)'), 'ticket_vehicle_details.group_code')
            ->orderBy('day')
            ->get()
            ->map(fn (object $row) => [
                'date' => (string) $row->day,
                'group_code' => (string) $row->group_code,
                'count' => (int) $row->total,
            ])
            ->values();

        // ── Lama penyelesaian tiket yang baru tertutup ───────────────
        // Metrik kualitas layanan yang sebelumnya tidak ada sama sekali di
        // dasbor. Dihitung dari selisih `closed_at - created_at` untuk tiket
        // yang tertutup dalam jendela, jadi yang diukur adalah tiket yang baru
        // saja selesai, bukan semua tiket selesai sejak dulu.
        //
        // Persentil dihitung di PHP, bukan SQL: MySQL tidak punya
        // percentile_cont, dan nilai yang dikembalikan sudah dinormalisasi ke
        // jam sehingga frontend tidak perlu tahu satuan aslinya.
        $durationDays = 30;
        $durations = Ticket::query()
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', Carbon::now()->subDays($durationDays)->startOfDay())
            ->get(['created_at', 'closed_at'])
            ->map(function (Ticket $ticket): float {
                $hours = $ticket->created_at->diffInHours($ticket->closed_at, false);

                return $hours > 0 ? (float) $hours : 0.0;
            })
            ->sort()
            ->values();

        $resolutionDuration = [
            'days' => $durationDays,
            'sample' => $durations->count(),
            'avg_hours' => $durations->isEmpty()
                ? null
                : round((float) $durations->avg(), 1),
            'p50_hours' => $this->percentile($durations, 0.5),
            'p90_hours' => $this->percentile($durations, 0.9),
        ];

        // ── WANSIS: laporan menurut status pengiriman ─────────────────
        $wansisPerStatus = WansisReport::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $wansis = [
            'sent' => (int) ($wansisPerStatus['sent'] ?? 0),
            'failed' => (int) ($wansisPerStatus['failed'] ?? 0),
            'queued' => (int) ($wansisPerStatus['queued'] ?? 0),
        ];

        // ── 5 tiket OPEN tertua ─────────────────────────────────────
        $oldestOpen = Ticket::query()
            ->whereHas('status', fn ($q) => $q->where('code', 'OPEN'))
            ->with(['ticketType:id,code,name', 'priority:id,code,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'ticket_no', 'subject', 'ticket_type_id', 'priority_id', 'created_at'])
            ->map(fn (Ticket $ticket) => [
                'id' => (int) $ticket->id,
                'ticket_no' => $ticket->ticket_no,
                'subject' => $ticket->subject,
                'ticket_type_code' => $ticket->ticketType?->code,
                'priority_code' => $ticket->priority?->code,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'age_days' => $ticket->created_at
                    ? (int) $ticket->created_at->startOfDay()->diffInDays(Carbon::now()->startOfDay())
                    : 0,
            ])
            ->values();

        return response()->json([
            'kpi' => $kpi,
            'funnel' => $funnel,
            'workload' => $workload,
            'trends' => [
                'days' => 365,
                'categories' => $categoryTrend,
                'products' => $productTrend,
            ],
            'duration' => $resolutionDuration,
            'wansis' => $wansis,
            'oldest_open' => $oldestOpen,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Persentil dari koleksi yang sudah terurut menaik.
     *
     * Pakai interpolasi linier di antara dua nilai terdekat — sama seperti
     * `PERCENTILE_CONT`. Nilai `$p` dalam rentang 0..1. Koleksi kosong
     * menghasilkan `null`, bukan 0, supaya "tidak ada data" tidak terbaca
     * sebagai "selesat dalam 0 jam" di dasbor.
     *
     * @param  Collection<int, float>  $sorted
     */
    private function percentile($sorted, float $p): ?float
    {
        $count = $sorted->count();

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return round((float) $sorted[0], 1);
        }

        $position = ($count - 1) * $p;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return round((float) $sorted[$lower], 1);
        }

        $weight = $position - $lower;

        return round((float) $sorted[$lower] * (1 - $weight) + (float) $sorted[$upper] * $weight, 1);
    }
}
