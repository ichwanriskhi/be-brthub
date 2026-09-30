<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * GET /api/admin/customers
     *
     * Daftar customer (profil user ter-sinkron auth-service) beserta
     * statistik tiket: total, aktif (belum terminal & belum closed),
     * selesai (terminal atau sudah closed).
     */
    public function index(Request $request)
    {
        $query = Customer::with([
            'user:id,full_name,email,phone_number,address,is_active,created_at',
        ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status')) {
            $status = (string) $request->string('status');
            if ($status === 'ACTIVE') {
                $query->where('customers.is_active', true);
            } elseif ($status === 'INACTIVE') {
                $query->where('customers.is_active', false);
            }
        }

        $perPage = min((int) $request->integer('per_page', 15) ?: 15, 200);
        $page = $customers = null;
        $customers = $query->select('customers.*')
            ->orderBy('customers.id')
            ->paginate($perPage);

        // Statistik tiket per customer (satu query untuk semua customer).
        $statsByCustomer = Ticket::query()
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.status_id')
            ->selectRaw(
                'tickets.customer_id, '.
                'COUNT(*) AS total, '.
                'SUM(CASE WHEN ticket_statuses.is_terminal = 0 AND tickets.closed_at IS NULL THEN 1 ELSE 0 END) AS active, '.
                'SUM(CASE WHEN ticket_statuses.is_terminal = 1 OR tickets.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved'
            )
            ->whereNotNull('tickets.customer_id')
            ->groupBy('tickets.customer_id')
            ->get()
            ->keyBy('customer_id');

        // Ringkasan agregat (seluruh customer, bukan hanya halaman ini).
        $summary = [
            'total_customers' => Customer::count(),
            'customers_with_tickets' => $statsByCustomer->count(),
            'total_tickets' => (int) $statsByCustomer->sum('total'),
            'active_tickets' => (int) $statsByCustomer->sum('active'),
            'resolved_tickets' => (int) $statsByCustomer->sum('resolved'),
        ];

        return response()->json([
            'data' => collect($customers->items())->map(function (Customer $c) use ($statsByCustomer) {
                $stats = $statsByCustomer->get($c->id);

                return [
                    'id' => $c->id,
                    'code' => sprintf('CUS-%05d', $c->id),
                    'user_id' => $c->user_id,
                    'is_active' => $c->is_active,
                    'name' => $c->user?->full_name,
                    'email' => $c->user?->email,
                    'phone' => $c->user?->phone_number,
                    'address' => $c->user?->address,
                    'created_at' => $c->user?->created_at ?? $c->created_at,
                    'tickets' => [
                        'total' => (int) ($stats->total ?? 0),
                        'active' => (int) ($stats->active ?? 0),
                        'resolved' => (int) ($stats->resolved ?? 0),
                    ],
                ];
            })->values(),
            'summary' => $summary,
            'current_page' => $customers->currentPage(),
            'last_page' => $customers->lastPage(),
            'per_page' => $customers->perPage(),
            'total' => $customers->total(),
        ]);
    }
}
