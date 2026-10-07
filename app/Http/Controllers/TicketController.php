<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\EmployeeProfile;
use App\Models\HandlerProgressEntry;
use App\Models\PriorityLevel;
use App\Models\ReviewLog;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Models\TicketRelation;
use App\Models\TicketRevision;
use App\Models\TicketSalesDetail;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\TicketVehicleDetail;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuthServiceClient;
use App\Services\PhoneNumber;
use App\Services\TicketActivityLogger;
use App\Services\UserSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    protected AuthServiceClient $authServiceClient;

    public function __construct(
        AuthServiceClient $authServiceClient,
        protected readonly ApprovalService $approvalService,
    ) {
        $this->authServiceClient = $authServiceClient;
    }

    /**
     * List all tickets with relations, filtering, and pagination
     */
    public function index(Request $request)
    {
        $query = Ticket::with([
            'reporterUser',
            'customer.user',
            'category.parent',
            'ticketType',
            'priority',
            'status',
            'vehicleDetail',
            'salesDetail',
            'relations.relatedTicket',
            'action',
        ])->with(['attachments' => function ($query) {
            // Hanya attachment yang langsung terkait ke ticket (lampiran pelapor saat pembuatan laporan)
            $query->whereNull('attachable_type')
                ->orWhere('attachable_type', 'App\\Models\\Ticket');
        }]);

        // Scope to the authenticated reporter only (used by "Laporan Saya")
        if (filter_var($request->input('mine', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('reporter_user_id', $request->user()?->id);
        }

        // Scope ke tiket yang pernah di-review oleh reviewer yang sedang login
        if (filter_var($request->input('reviewed_by_me', false), FILTER_VALIDATE_BOOLEAN)) {
            $reviewerEmployeeId = $request->user()?->employeeProfile?->id;
            if ($reviewerEmployeeId) {
                // Hanya jejak routing reviewer. Log approval (APPROVAL_INITIAL /
                // APPROVAL_FINAL) adalah keputusan approver, bukan riwayat reviewer
                // — walau ditulis orang yang sama saat satu pegawai memegang
                // posisi approver DAN role reviewer.
                $query->whereHas('reviewLogs', function ($q) use ($reviewerEmployeeId) {
                    $q->where('reviewer_employee_id', $reviewerEmployeeId)
                        ->where('review_type', 'INITIAL');
                });
            } else {
                // Bukan pegawai → tidak ada riwayat review
                $query->whereRaw('1 = 0');
            }
        }

        // Filter by reviewer employee id (explicit)
        if ($request->filled('reviewed_by')) {
            $query->whereHas('reviewLogs', function ($q) use ($request) {
                $q->where('reviewer_employee_id', $request->input('reviewed_by'));
            });
        }

        // Filter by reporter type (CUSTOMER / EMPLOYEE)
        if ($request->filled('reporter_type')) {
            $reporterType = strtoupper($request->input('reporter_type'));
            $query->whereHas('reporterUser', function ($q) use ($reporterType) {
                if ($reporterType === 'EMPLOYEE') {
                    $q->has('employeeProfile');
                } elseif ($reporterType === 'CUSTOMER') {
                    $q->has('customer');
                }
            });
        }

        // Filter by status
        if ($request->filled('status_id')) {
            $query->where('status_id', $request->input('status_id'));
        }

        if ($request->filled('status_code')) {
            $statusCode = strtoupper($request->input('status_code'));
            $query->whereHas('status', function ($q) use ($statusCode) {
                $q->where('code', $statusCode);
            });
        }

        // Filter by multiple status codes (mis. riwayat: CLOSED + REJECTED)
        if ($request->filled('status_codes')) {
            $statusCodes = array_values(array_filter(
                array_map('strtoupper', (array) $request->input('status_codes'))
            ));
            if (! empty($statusCodes)) {
                $query->whereHas('status', function ($q) use ($statusCodes) {
                    $q->whereIn('code', $statusCodes);
                });
            }
        }

        // Filter by priority
        if ($request->filled('priority_id')) {
            $query->where('priority_id', $request->input('priority_id'));
        }

        if ($request->filled('priority_code')) {
            $priorityCode = strtoupper($request->input('priority_code'));
            $query->whereHas('priority', function ($q) use ($priorityCode) {
                $q->where('code', $priorityCode);
            });
        }

        if ($request->filled('ticket_type_code')) {
            $typeCode = strtoupper($request->input('ticket_type_code'));
            $query->whereHas('ticketType', function ($q) use ($typeCode) {
                $q->where('code', $typeCode);
            });
        }

        if ($request->filled('handler_id')) {
            $handlerId = $request->input('handler_id');
            $query->whereHas('assignments', function ($q) use ($handlerId) {
                $q->where('assigned_to_employee_id', $handlerId)
                    ->where('is_active', true)
                    ->whereNull('unassigned_at');
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('tickets.created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('tickets.created_at', '<=', $request->input('date_to'));
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        // Filter by ticket type
        if ($request->filled('ticket_type_id')) {
            $query->where('ticket_type_id', $request->input('ticket_type_id'));
        }

        // Filter by lini produk = kode grup WANSIS (vehicle details)
        if ($request->filled('product_group_code')) {
            $groupCode = $request->input('product_group_code');
            $query->whereHas('vehicleDetail', function ($q) use ($groupCode) {
                $q->where('group_code', $groupCode);
            });
        }

        // Search by ticket_no / subject / description
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_no', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $paginator = $query->latest()->paginate($perPage);

        // Ringkasan jumlah tiket selesai / ditolak untuk halaman riwayat admin.
        if (filter_var($request->input('with_summary', false), FILTER_VALIDATE_BOOLEAN)) {
            $baseClosedQuery = Ticket::query()
                ->whereHas('status', fn ($q) => $q->whereIn('code', ['CLOSED', 'REJECTED']));
            $closedCount = (clone $baseClosedQuery)->whereHas('status', fn ($q) => $q->where('code', 'CLOSED'))->count();
            $rejectedCount = (clone $baseClosedQuery)->whereHas('status', fn ($q) => $q->where('code', 'REJECTED'))->count();

            return response()->json(array_merge($paginator->toArray(), [
                'summary' => [
                    'closed_count' => $closedCount,
                    'rejected_count' => $rejectedCount,
                    'total_count' => $closedCount + $rejectedCount,
                ],
            ]));
        }

        return response()->json($paginator);
    }

    /**
     * Detail of single ticket
     */
    public function show($id)
    {
        $query = Ticket::with([
            'reporterUser.employeeProfile.department',
            'reporterUser.employeeProfile.position',
            'customer.user',
            'category.parent',
            'ticketType',
            'priority',
            'status',
            'vehicleDetail',
            'salesDetail',
            'relations.relatedTicket',
            'action',
            'latestRevision',
            'latestReviewLog.destinationDepartment',
            // Nomor report WANSIS (hanya untuk klaim distribusi). Kolom lain
            // sengaja tidak diambil — `payload_json`/`response_json` berukuran
            // besar dan tidak dipakai UI.
            'wansisReports:id,ticket_id,wansis_report_id',
            'assignments.assignedToEmployee.user',
            'assignments.assignedByEmployee.user',
            'resolutions.attachments',
            'resolutions.reviewLog',
            'resolutions.submittedBy',
            'activities.actor:id,full_name',
        ])->with(['attachments' => function ($query) {
            // Hanya attachment yang langsung terkait ke ticket (lampiran pelapor saat pembuatan laporan)
            $query->whereNull('attachable_type')
                ->orWhere('attachable_type', 'App\\Models\\Ticket');
        }]);

        if (is_numeric($id)) {
            $ticket = $query->findOrFail($id);
        } else {
            $ticket = $query->where('ticket_no', $id)->firstOrFail();
        }

        // Reporters may only read their own tickets. Staff with a reviewer/handler/
        // admin role may read everything. Approvers may read tiket yang menunggu
        // persetujuan mereka (initial maupun final) — wewenang dihitung dari posisi.
        $user = request()->user();
        if (
            $user
            && $ticket->reporter_user_id !== $user->id
            && ! $user->hasRole('admin')
            && ! $user->hasRole('handler')
            && ! $user->hasRole('reviewer')
            && ! $this->canUserApprove($user, $ticket)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak berhak melihat tiket ini.',
            ], 403);
        }

        // Progres pengerjaan handler (relasi via assignment, bukan relasi langsung)
        $progressQuery = HandlerProgressEntry::query()
            ->with('actor:id,full_name', 'attachments')
            ->whereHas('assignment', fn ($q) => $q->where('ticket_id', $ticket->id)
                ->where('assignment_type', 'HANDLER'));

        // Reporter hanya melihat progres publik; entri internal khusus
        // handler/reviewer. Approver tetap boleh melihat semuanya.
        $isOwner = $user && $ticket->reporter_user_id === $user->id;
        $isStaff = $user && ($user->hasRole('admin') || $user->hasRole('handler') || $user->hasRole('reviewer'));
        if ($isOwner && ! $isStaff && ! $this->canUserApprove($user, $ticket)) {
            $progressQuery->where('is_internal', false);
        }

        $progress = $progressQuery->orderByDesc('id')->get();

        return response()->json([
            ...$ticket->toArray(),
            'handler_progress' => $progress,
        ]);
    }

    /**
     * Apakah user ini berhak melakukan approval pada tiket (berdasarkan posisi).
     *
     * Approver adalah posisi, bukan role — jadi pengecekan lewat ApprovalService.
     */
    private function canUserApprove($user, Ticket $ticket): bool
    {
        $employee = $user instanceof User ? $user->employeeProfile : null;

        return $employee !== null && $this->approvalService->canApprove($employee, $ticket);
    }

    /**
     * Store new ticket in normalized schema
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reporter_type' => 'nullable|in:CUSTOMER,EMPLOYEE,customer,employee',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'address' => 'nullable|string',

            // Ticket main fields
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'nullable|exists:categories,id',
            'category' => 'nullable|string',
            'subcategory' => 'nullable|string',
            'ticket_type_id' => 'nullable|exists:ticket_types,id',
            'ticket_type' => 'nullable|string',
            'priority_id' => 'nullable|exists:priority_levels,id',
            'priority' => 'nullable|string',
            'status_id' => 'nullable|exists:ticket_statuses,id',
            'approval_type' => 'nullable|in:DIREKSI,GENERAL_MANAGER,OPERATIONAL_MANAGER,DIVISION',

            // On behalf of customer
            'is_report_for_customer' => 'nullable',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email:rfc|max:255',
            'customer_address' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',

            // Vehicle detail — lini produk = kode grup WANSIS (bukan FK lokal;
            // master tunggal ada di SAP). Tanpa lookup nama.
            'product_group_code' => 'nullable|string|max:50',
            'vehicle_model' => 'nullable|string|max:255',

            // Sales / Claims detail
            'so_number' => 'nullable|string|max:255',
            'sales_name' => 'nullable|string|max:255',
            'claimed_items' => 'nullable',

            // Relations
            'is_related' => 'nullable',
            'related_ticket_id' => 'nullable',
            'relation_type' => 'nullable|string',

            // Attachments
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,mp4,webm|max:10240',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $relatedTicket = null;
        $isRelated = $request->input('is_related');
        if ($isRelated === 'yes' || $isRelated === true || $isRelated === 'true') {
            $relatedTicketIdentifier = $request->input('related_ticket_id');
            $relatedTicketQuery = Ticket::query()
                ->where('reporter_user_id', $user->id)
                ->whereHas('status', function ($q) {
                    $q->where('code', 'CLOSED');
                });

            if (is_numeric($relatedTicketIdentifier)) {
                $relatedTicketQuery->whereKey($relatedTicketIdentifier);
            } else {
                $relatedTicketQuery->where('ticket_no', $relatedTicketIdentifier);
            }

            $relatedTicket = $relatedTicketQuery->first();

            if (! $relatedTicket) {
                return response()->json([
                    'message' => 'Tiket terkait harus merupakan tiket Anda yang sudah ditutup.',
                    'errors' => [
                        'related_ticket_id' => ['Tiket terkait tidak valid.'],
                    ],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // 1. Update user address or name if provided
            $userUpdates = [];
            if ($request->filled('address')) {
                $userUpdates['address'] = $request->address;
            }
            if ($request->filled('name') && empty($user->full_name)) {
                $userUpdates['full_name'] = $request->name;
            }
            if (! empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // 2. Ensure EmployeeProfile or Customer record for the reporter user
            $reporterType = strtoupper($request->input('reporter_type', 'CUSTOMER'));
            if ($reporterType === 'EMPLOYEE' && $request->filled('department_id') && $request->filled('position_id')) {
                $employeeProfile = EmployeeProfile::where('user_id', $user->id)->first();
                if (! $employeeProfile) {
                    $empNumber = 'EMP-'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT);
                    EmployeeProfile::create([
                        'user_id' => $user->id,
                        'employee_number' => $empNumber,
                        'department_id' => $request->department_id,
                        'position_id' => $request->position_id,
                        'status' => 'active',
                    ]);
                } else {
                    $employeeProfile->update([
                        'department_id' => $request->department_id,
                        'position_id' => $request->position_id,
                    ]);
                }
            } elseif ($reporterType !== 'EMPLOYEE') {
                // Employees without a complete department/position must not get
                // a customer record — only real customer reporters do.
                Customer::firstOrCreate(
                    ['user_id' => $user->id],
                    ['is_active' => true]
                );
            }

            // 3. Resolve customer_id if reported on behalf of a customer
            $customerId = null;
            $isReportForCustomer = filter_var($request->input('is_report_for_customer', false), FILTER_VALIDATE_BOOLEAN);
            if ($isReportForCustomer && $request->filled('customer_name')) {
                // Canonical +62 format so lookups always match IdP-synced rows.
                $customerPhone = PhoneNumber::normalize($request->input('customer_phone'));
                $customerEmail = $request->filled('customer_email')
                    ? strtolower(trim((string) $request->input('customer_email')))
                    : null;

                $customerUser = $this->resolveCustomerUser($customerPhone, $customerEmail);

                if ($customerUser) {
                    // Reuse the existing account; only backfill missing details.
                    $customerUpdates = [];
                    if (empty($customerUser->full_name)) {
                        $customerUpdates['full_name'] = $request->customer_name;
                    }
                    if ($request->filled('customer_address') && empty($customerUser->address)) {
                        $customerUpdates['address'] = $request->customer_address;
                    }
                    if ($customerEmail && empty($customerUser->email)) {
                        $customerUpdates['email'] = $customerEmail;
                    }
                    if ($customerPhone && empty($customerUser->phone_number)) {
                        $customerUpdates['phone_number'] = $customerPhone;
                    }
                    if (! empty($customerUpdates)) {
                        $customerUser->update($customerUpdates);
                    }
                } else {
                    // Fallback: final local lookup by normalized email/phone to prevent
                    // duplicate key errors when IdP lookup missed an existing local row.
                    $fallbackUser = User::query()
                        ->when($customerPhone, fn ($q) => $q->where('phone_number', $customerPhone))
                        ->when($customerEmail, fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$customerEmail]))
                        ->first();

                    if ($fallbackUser) {
                        $customerUser = $fallbackUser;
                        // Backfill any missing details
                        $customerUpdates = [];
                        if (empty($customerUser->full_name)) {
                            $customerUpdates['full_name'] = $request->customer_name;
                        }
                        if ($request->filled('customer_address') && empty($customerUser->address)) {
                            $customerUpdates['address'] = $request->customer_address;
                        }
                        if ($customerEmail && empty($customerUser->email)) {
                            $customerUpdates['email'] = $customerEmail;
                        }
                        if ($customerPhone && empty($customerUser->phone_number)) {
                            $customerUpdates['phone_number'] = $customerPhone;
                        }
                        if (! empty($customerUpdates)) {
                            $customerUser->update($customerUpdates);
                        }
                    } else {
                        $customerUser = User::create([
                            'full_name' => $request->customer_name,
                            'phone_number' => $customerPhone,
                            'email' => $customerEmail,
                            'address' => $request->customer_address,
                            'source' => 'local',
                        ]);
                    }
                }

                $customerRecord = Customer::firstOrCreate(
                    ['user_id' => $customerUser->id],
                    ['is_active' => true]
                );
                $customerId = $customerRecord->id;
            } elseif ($request->filled('customer_id')) {
                $customerId = $request->customer_id;
            }

            // 4. Resolve category_id — kontrak: simpan ID SUBKATEGORI (child).
            // Kategori parent diturunkan via relasi parent_category_id, jadi
            // menyimpan parent membuat subcategory hilang di response.
            // FE report/new mengirim: category = NAME parent, subcategory = CODE child.
            $categoryId = $request->input('category_id');
            if (! $categoryId && $request->filled('subcategory')) {
                $subInput = trim((string) $request->subcategory);
                // 4a. Exact match by code (jalur utama FE baru).
                $sub = Category::where('code', $subInput)->first();
                // 4b. Code dinormalisasi (toleransi spasi/case).
                if (! $sub) {
                    $normalized = strtoupper((string) preg_replace('/\s+/', '_', $subInput));
                    $sub = Category::whereRaw('UPPER(REPLACE(code, " ", "_")) = ?', [$normalized])->first();
                }
                // 4c. Legacy: name (client lama mengirim nama subkategori).
                if (! $sub) {
                    // Prefer child bila nama ambigu (mis. "Lainnya" ada di 2 parent).
                    $sub = Category::where('name', $subInput)->whereNotNull('parent_category_id')->first()
                        ?? Category::where('name', $subInput)->first();
                }
                if ($sub) {
                    $categoryId = $sub->id;
                }
            }
            if (! $categoryId && $request->filled('category')) {
                $cat = Category::where('name', $request->category)->first()
                    ?? Category::where('code', $request->category)->first();
                if ($cat) {
                    $categoryId = $cat->id;
                }
            }
            if (! $categoryId) {
                $categoryId = Category::first()->id ?? 1;
            }
            if ($request->filled('subcategory')) {
                $stored = Category::find($categoryId);
                if ($stored && is_null($stored->parent_category_id)) {
                    \Log::warning('Ticket created with parent category_id; subcategory may be unresolved.', [
                        'category_input' => $request->input('category'),
                        'subcategory_input' => $request->input('subcategory'),
                        'stored_category_id' => $categoryId,
                    ]);
                }
            }

            // 5. Resolve ticket_type_id
            $ticketTypeId = $request->input('ticket_type_id');
            if (! $ticketTypeId && $request->filled('ticket_type')) {
                $tt = TicketType::where('code', strtoupper($request->ticket_type))->first();
                if ($tt) {
                    $ticketTypeId = $tt->id;
                }
            }
            if (! $ticketTypeId) {
                $ticketTypeId = TicketType::first()->id ?? 1;
            }

            // 6. Resolve priority_id (Null by default, assigned by reviewer later)
            $priorityId = $request->input('priority_id');
            if (! $priorityId && $request->filled('priority')) {
                $p = PriorityLevel::where('code', strtoupper($request->priority))->first();
                if ($p) {
                    $priorityId = $p->id;
                }
            }

            // 7. Resolve status_id (Default: OPEN)
            $statusId = $request->input('status_id') ?? (TicketStatus::where('code', 'OPEN')->value('id') ?? 1);

            // 8. Generate ticket_no
            $ticketNo = $this->generateTicketNumber();

            // 9. Create main Ticket record
            $ticket = Ticket::create([
                'ticket_no' => $ticketNo,
                'reporter_user_id' => $user->id,
                'customer_id' => $customerId,
                'category_id' => $categoryId,
                'ticket_type_id' => $ticketTypeId,
                'priority_id' => $priorityId,
                'status_id' => $statusId,
                'subject' => $request->input('subject'),
                'description' => $request->input('description'),
                'approval_type' => $request->input('approval_type'),
            ]);

            // 10. Vehicle detail — simpan kode grup apa adanya, tanpa
            // lookup nama (anti-pola Product::where('name', ...)).
            $groupCode = $request->input('product_group_code');
            $vehicleModel = $request->input('vehicle_model');
            if ($groupCode || $vehicleModel) {
                TicketVehicleDetail::create([
                    'ticket_id' => $ticket->id,
                    'group_code' => $groupCode,
                    'vehicle_model' => $vehicleModel,
                ]);
            }

            // 11. Sales details
            $soNumber = $request->input('so_number');
            $salesName = $request->input('sales_name');
            $claimedItems = $request->input('claimed_items');
            if (is_string($claimedItems)) {
                $claimedItems = json_decode($claimedItems, true);
            }
            if ($soNumber || $salesName || ! empty($claimedItems)) {
                TicketSalesDetail::create([
                    'ticket_id' => $ticket->id,
                    'so_number' => $soNumber,
                    'sales_name' => $salesName,
                    'claimed_items' => $claimedItems,
                ]);
            }

            // 12. Relations
            if ($relatedTicket) {
                $relationType = $request->input('relation_type', 'RELATED_TO');
                if ($relationType === 'REPEATED_ISSUE') {
                    $relationType = 'RECURRING_OF';
                }
                if ($relationType === 'FOLLOW_UP') {
                    $relationType = 'FOLLOW_UP_OF';
                }
                if (! in_array($relationType, ['RECURRING_OF', 'RELATED_TO', 'FOLLOW_UP_OF'])) {
                    $relationType = 'RELATED_TO';
                }

                TicketRelation::create([
                    'ticket_id' => $ticket->id,
                    'related_ticket_id' => $relatedTicket->id,
                    'relation_type' => $relationType,
                ]);
            }

            // 13. File Attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('attachments/tickets', 'public');
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'attachable_type' => Ticket::class,
                        'attachable_id' => $ticket->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'uploaded_by_user_id' => $user->id,
                    ]);
                }
            }

            DB::commit();

            TicketActivityLogger::record(
                $ticket,
                TicketActivity::TYPE_CREATED,
                $ticket->reporter_user_id,
                'Laporan dibuat.',
            );

            return response()->json([
                'message' => 'Tiket berhasil dibuat',
                'data' => $ticket->load([
                    'reporterUser',
                    'customer.user',
                    'category.parent',
                    'ticketType',
                    'priority',
                    'status',
                    'vehicleDetail',
                    'salesDetail',
                    'relations.relatedTicket',
                    'attachments',
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal membuat tiket: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update ticket
     */
    public function update(Request $request, $id)
    {
        $ticket = $this->findTicket($id);

        $validated = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'ticket_type_id' => 'sometimes|exists:ticket_types,id',
            'priority_id' => 'sometimes|exists:priority_levels,id',
            'status_id' => 'sometimes|exists:ticket_statuses,id',
            'subject' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'approval_type' => 'sometimes|in:DIREKSI,GENERAL_MANAGER,OPERATIONAL_MANAGER,DIVISION',
            'product_group_code' => 'sometimes|nullable|string|max:50',
            'vehicle_model' => 'sometimes|nullable|string|max:255',
            'so_number' => 'sometimes|nullable|string|max:255',
            'sales_name' => 'sometimes|nullable|string|max:255',
            'claimed_items' => 'sometimes|nullable|array',
        ]);

        $ticket->update(collect($validated)->only([
            'category_id',
            'ticket_type_id',
            'priority_id',
            'status_id',
            'subject',
            'description',
            'approval_type',
        ])->toArray());

        // Update vehicle detail if provided
        if ($request->has('product_group_code') || $request->has('vehicle_model')) {
            $ticket->vehicleDetail()->updateOrCreate(
                ['ticket_id' => $ticket->id],
                [
                    'group_code' => $request->product_group_code,
                    'vehicle_model' => $request->vehicle_model,
                ]
            );
        }

        // Update sales detail if provided
        if ($request->has('so_number') || $request->has('sales_name') || $request->has('claimed_items')) {
            $ticket->salesDetail()->updateOrCreate(
                ['ticket_id' => $ticket->id],
                [
                    'so_number' => $request->so_number,
                    'sales_name' => $request->sales_name,
                    'claimed_items' => $request->claimed_items,
                ]
            );
        }

        return response()->json([
            'message' => 'Tiket berhasil diupdate',
            'data' => $ticket->load([
                'reporterUser',
                'customer.user',
                'category.parent',
                'ticketType',
                'priority',
                'status',
                'vehicleDetail',
                'salesDetail',
                'latestRevision',
            ]),
        ]);
    }

    /**
     * Cari tiket berdasarkan id numerik ATAU ticket_no.
     *
     * FE memakai ticket_no sebagai identifier publik (mis. TKT-20260929-00005),
     * jadi setiap endpoint dengan parameter {id} harus menerima keduanya.
     * Pola sama dengan UnitController::findTicket(), ApprovalController::findTicket(),
     * TicketInteractionController::resolveTicket(), dan TicketController::show().
     */
    private function findTicket(string $id): Ticket
    {
        $query = Ticket::query();

        return is_numeric($id)
            ? $query->findOrFail($id)
            : $query->where('ticket_no', $id)->firstOrFail();
    }

    /**
     * Submit initial review (reviewer): route, request rework, or reject.
     *
     * Creates a review_log + updates ticket status/routing.
     * Status flow saat ROUTE: OPEN → PENDING_APPROVAL (semua approval_type butuh approver).
     */
    public function submitReview(Request $request, $id)
    {
        $validated = $request->validate([
            'decision' => 'required|in:ROUTE,REQUEST_REWORK,REJECT',
            'priority_id' => 'nullable|exists:priority_levels,id',
            'approval_type' => 'nullable|in:DIREKSI,GENERAL_MANAGER,OPERATIONAL_MANAGER,DIVISION',
            'destination_department_id' => 'nullable|exists:departments,id',
            // Frontend mengirim action code (RETURN_AND_REPLACE, dst), bukan id
            // numerik. Resolve ke id di sini agar tahan terhadap perubahan id
            // seeding; validasi ketatannya dikerjakan di blok ROUTE di bawah.
            'action_id' => 'nullable|string',
            'notes' => 'nullable|string|max:2000',
            // Working copy revisions (JSON diff - only changed fields)
            'revisions' => 'nullable|array',
            'revisions.subject' => 'nullable|string|max:255',
            'revisions.ticket_type_id' => 'nullable|exists:ticket_types,id',
            'revisions.category_id' => 'nullable|exists:categories,id',
            'revisions.description' => 'nullable|string',
            'revisions.vehicle_detail' => 'nullable|array',
            'revisions.vehicle_detail.vehicle_model' => 'nullable|string|max:255',
            'revisions.vehicle_detail.group_code' => 'nullable|string|max:50',
            'revisions.sales_detail' => 'nullable|array',
            'revisions.sales_detail.so_number' => 'nullable|string|max:255',
            'revisions.sales_detail.sales_name' => 'nullable|string|max:255',
            'revisions.claimed_items' => 'nullable|array',
        ]);

        // FE memakai ticket_no sebagai identifier publik, jadi resolution harus
        // mendukung id numerik ATAU ticket_no (lihat findTicket()).
        $ticket = $this->findTicket($id)->load([
            'reporterUser.employeeProfile',
            'category',
            'status',
            'vehicleDetail',
            'salesDetail',
        ]);

        // Pastikan user login & punya employee profile (reviewer = pegawai)
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        $employeeProfile = $user->employeeProfile;
        if (! $employeeProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Reviewer harus memiliki profil pegawai.',
            ], 422);
        }

        $decision = strtoupper($validated['decision']);

        // ── Validasi tambahan sesuai decision ──────────────────────────────
        if ($decision === 'ROUTE') {
            if (empty($validated['approval_type'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tujuan approval / eskalasi wajib diisi.',
                    'errors' => ['approval_type' => ['Tujuan approval / eskalasi wajib diisi.']],
                ], 422);
            }
            if (empty($validated['destination_department_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit / departemen tujuan wajib diisi.',
                    'errors' => ['destination_department_id' => ['Unit / departemen tujuan wajib diisi.']],
                ], 422);
            }
            // Aksi handler WAJIB bila kategori tiket punya opsi aksi di matrix
            // `category_actions` — aturan yang sama dipakai FE (opsi ada → wajib).
            $hasActionOptions = $ticket->category_id
                ? \DB::table('category_actions')->where('category_id', $ticket->category_id)->exists()
                : false;
            if ($hasActionOptions && empty($validated['action_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aksi handler wajib dipilih untuk kategori ini.',
                    'errors' => ['action_id' => ['Aksi handler wajib dipilih untuk kategori ini.']],
                ], 422);
            }
            // action_id harus valid untuk kategori tiket (jika kategori butuh action)
            if (! empty($validated['action_id'])) {
                $actionId = $this->resolveActionId($validated['action_id']);
                if ($actionId === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aksi yang dipilih tidak dikenali.',
                        'errors' => ['action_id' => ['Aksi yang dipilih tidak dikenali.']],
                    ], 422);
                }

                $isValidAction = \DB::table('category_actions')
                    ->where('category_id', $ticket->category_id)
                    ->where('action_id', $actionId)
                    ->exists();
                if (! $isValidAction) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aksi tidak tersedia untuk kategori tiket ini.',
                        'errors' => ['action_id' => ['Aksi tidak tersedia untuk kategori ini.']],
                    ], 422);
                }

                // Simpan id terresolve supaya blok update memakai nilai yang benar
                $validated['action_id'] = (string) $actionId;
            }
        }

        // ── Resolve status tujuan ───────────────────────────────────────────
        $statusMap = [
            'ROUTE' => 'PENDING_APPROVAL',
            'REQUEST_REWORK' => 'REWORK_REQUIRED',
            'REJECT' => 'REJECTED',
        ];
        $targetStatusCode = $statusMap[$decision];
        $targetStatusId = TicketStatus::where('code', $targetStatusCode)->value('id');
        if (! $targetStatusId) {
            return response()->json([
                'success' => false,
                'message' => "Status {$targetStatusCode} belum tersedia di database.",
            ], 500);
        }

        // ── Create review log ──────────────────────────────────────────────
        $previousReview = $ticket->reviewLogs()->latest('reviewed_at')->first();

        $reviewLog = ReviewLog::create([
            'ticket_id' => $ticket->id,
            'reviewer_employee_id' => $employeeProfile->id,
            'review_type' => 'INITIAL',
            'decision' => $decision,
            'destination_department_id' => $decision === 'ROUTE'
                ? $validated['destination_department_id']
                : null,
            'previous_review_id' => $previousReview?->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        // ── Create revision (working copy) if revisions payload provided ──────
        if (! empty($validated['revisions'])) {
            $originData = $this->buildOriginData($ticket);
            $diff = $this->computeDiff($originData, $validated['revisions']);

            if (! empty($diff)) {
                $lastRevisionNo = $ticket->revisions()->max('revision_no') ?? 0;

                TicketRevision::create([
                    'ticket_id' => $ticket->id,
                    'revision_no' => $lastRevisionNo + 1,
                    'revised_by_user_id' => $user->id,
                    'changes' => $diff,
                    'notes' => $validated['notes'] ?? null,
                ]);
            }
        }

        // ── Update ticket ──────────────────────────────────────────────────
        $ticketUpdates = [
            'status_id' => $targetStatusId,
        ];
        if ($decision === 'ROUTE') {
            $ticketUpdates['approval_type'] = $validated['approval_type'];
            $ticketUpdates['priority_id'] = $validated['priority_id'] ?? $ticket->priority_id;
            $ticketUpdates['action_id'] = $validated['action_id'] ?? null;
            // Disimpan di ticket juga (bukan hanya di review log) karena
            // ApprovalService butuh ini untuk approval_type DIVISION & unit
            // assignment saat approve.
            $ticketUpdates['destination_department_id'] = $validated['destination_department_id'] ?? null;
        }
        $ticket->update($ticketUpdates);

        $activityType = match ($decision) {
            'ROUTE' => TicketActivity::TYPE_REVIEW_ROUTED,
            'REQUEST_REWORK' => TicketActivity::TYPE_REVIEW_REWORK,
            default => TicketActivity::TYPE_REVIEW_REJECTED,
        };
        $activityDescription = match ($decision) {
            'ROUTE' => 'Tinjauan awal selesai. Tiket diteruskan ke approver ('.$validated['approval_type'].').',
            'REQUEST_REWORK' => 'Tiket dikembalikan untuk diperbaiki.',
            default => 'Laporan ditolak reviewer.',
        };
        TicketActivityLogger::record(
            $ticket,
            $activityType,
            $user->id,
            $activityDescription,
            ['status' => 'OPEN'],
            ['status' => $targetStatusCode],
        );

        return response()->json([
            'success' => true,
            'message' => $decision === 'ROUTE'
                ? 'Tiket berhasil diteruskan ke approver.'
                : ($decision === 'REQUEST_REWORK'
                    ? 'Tiket dikembalikan untuk diperbaiki.'
                    : 'Tiket berhasil ditolak.'),
            'data' => $ticket->fresh()->load([
                'reporterUser', 'customer.user', 'category.parent', 'ticketType',
                'priority', 'status', 'action', 'vehicleDetail', 'salesDetail',
            ]),
        ]);
    }

    /**
     * Resolve action_id yang dikirim frontend ke id numerik di tabel actions.
     *
     * FE memakai action code (RETURN_AND_REPLACE, REPLACE_ONLY, dst), tapi
     * kolom tickets.action_id + tabel category_actions memakai id. Terima
     * keduanya agar tidak pecah bila ada client lawas yang masih mengirim id.
     *
     * @return int|null id action, atau null bila tidak ditemukan.
     */
    private function resolveActionId(mixed $value): ?int
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $query = \DB::table('actions');

        if (is_numeric($value)) {
            $row = $query->where('id', (int) $value)->first();
        } else {
            $row = $query->where('code', strtoupper(trim($value)))->first();
        }

        return $row ? (int) $row->id : null;
    }

    /**
     * Build origin data snapshot from ticket (what reporter originally submitted).
     * Only includes fields that reviewer CAN edit.
     */
    private function buildOriginData(Ticket $ticket): array
    {
        $vehicle = $ticket->vehicleDetail;
        $sales = $ticket->salesDetail;

        return [
            'subject' => $ticket->subject,
            'ticket_type_id' => $ticket->ticket_type_id,
            'category_id' => $ticket->category_id,
            'description' => $ticket->description,
            'vehicle_detail' => $vehicle ? [
                'vehicle_model' => $vehicle->vehicle_model,
                'group_code' => $vehicle->group_code,
            ] : null,
            'sales_detail' => $sales ? [
                'so_number' => $sales->so_number,
                'sales_name' => $sales->sales_name,
            ] : null,
            'claimed_items' => $sales?->claimed_items ?? [],
        ];
    }

    /**
     * Compute diff between origin and new values.
     * Only includes fields that actually changed.
     *
     * Perbandingan dilakukan SETELAH normalisasi agar tidak menyimpan
     * no-op diff:
     * - skalar: bandingkan sebagai string (4 ≡ "4"), trim string,
     *   null/'' dianggap sama (keduanya "kosong"),
     * - array (claimed_items): normalisasi tiap item (buang key kosong,
     *   samakan alias qty/quantity, urutkan key), urutkan item by id,
     *   lalu bandingkan hasil json_encode-nya.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function computeDiff(array $origin, array $new): array
    {
        $diff = [];

        $fields = [
            'subject',
            'ticket_type_id',
            'category_id',
            'description',
            'vehicle_detail' => ['vehicle_model', 'group_code'],
            'sales_detail' => ['so_number', 'sales_name'],
            'claimed_items',
        ];

        foreach ($fields as $key => $subFields) {
            if (is_int($key)) {
                // Top-level field
                $field = $subFields;
                $oldVal = $origin[$field] ?? null;
                $newVal = $new[$field] ?? null;
                if (! $this->sameScalar($oldVal, $newVal)) {
                    $diff[$field] = ['old' => $oldVal, 'new' => $newVal];
                }
            } else {
                // Nested object (vehicle_detail, sales_detail)
                $oldNested = $origin[$key] ?? null;
                $newNested = $new[$key] ?? null;

                if (! $oldNested && ! $newNested) {
                    continue;
                }

                $nestedDiff = [];
                foreach ($subFields as $subField) {
                    $oldVal = is_array($oldNested) ? ($oldNested[$subField] ?? null) : null;
                    $newVal = is_array($newNested) ? ($newNested[$subField] ?? null) : null;
                    if (! $this->sameScalar($oldVal, $newVal)) {
                        $nestedDiff[$subField] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }

                if (! empty($nestedDiff)) {
                    $diff[$key] = $nestedDiff;
                }
            }
        }

        // claimed_items - bandingkan SETELAH normalisasi (buang key kosong,
        // samakan alias, urutkan) agar tidak menyimpan no-op diff.
        $oldClaimed = $this->normalizeClaimItems($origin['claimed_items'] ?? []);
        $newClaimed = $this->normalizeClaimItems($new['claimed_items'] ?? []);
        if (json_encode($oldClaimed) !== json_encode($newClaimed)) {
            $diff['claimed_items'] = ['old' => $origin['claimed_items'] ?? [], 'new' => $new['claimed_items'] ?? []];
        }

        return $diff;
    }

    /**
     * Bandingkan dua nilai skalar secara semantik.
     *
     * - null dan '' dianggap sama (keduanya "kosong"),
     * - angka vs numeric-string dianggap sama (4 ≡ "4"),
     * - string di-trim sebelum dibandingkan.
     */
    private function sameScalar(mixed $old, mixed $new): bool
    {
        $oldEmpty = $old === null || $old === '';
        $newEmpty = $new === null || $new === '';
        if ($oldEmpty || $newEmpty) {
            return $oldEmpty && $newEmpty;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return ((string) $old) === ((string) $new);
        }
        if (is_string($old) && is_string($new)) {
            return trim($old) === trim($new);
        }

        return $old === $new;
    }

    /**
     * Normalisasi daftar claimed_items untuk perbandingan diff.
     *
     * - tiap item: buang key kosong ('' / null), samakan alias
     *   qty/quantity (quantity menang), urutkan key,
     * - daftar: urutkan item berdasarkan kunci stabil (id, kode, nama)
     *   agar urutan tidak dianggap perubahan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeClaimItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        // Daftar asosiatif tunggal (bukan list) → bungkus jadi satu item.
        if (! array_is_list($items)) {
            $items = [$items];
        }
        $out = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $item = $raw;
            if (array_key_exists('quantity', $item) || array_key_exists('qty', $item)) {
                $q = $item['quantity'] ?? $item['qty'];
                unset($item['qty']);
                if ($q === '' || $q === null) {
                    unset($item['quantity']);
                } else {
                    $item['quantity'] = $q;
                }
            }
            // Samakan alias reason/issueDescription (satu makna, dua nama key).
            if (array_key_exists('reason', $item) || array_key_exists('issueDescription', $item)) {
                $reason = $item['reason'] ?? $item['issueDescription'];
                unset($item['issueDescription']);
                if ($reason === '' || $reason === null) {
                    unset($item['reason']);
                } else {
                    $item['reason'] = is_string($reason) ? trim($reason) : $reason;
                }
            }
            $norm = [];
            ksort($item);
            foreach ($item as $k => $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                $norm[$k] = is_numeric($v) && ! is_string($v) ? (string) $v : $v;
            }
            $out[] = $norm;
        }
        usort($out, fn ($a, $b) => strcmp(
            json_encode([$a['id'] ?? null, $a['itemCode1'] ?? null, $a['itemName1'] ?? null, $a['itemCode2'] ?? null, $a['itemName2'] ?? null]),
            json_encode([$b['id'] ?? null, $b['itemCode1'] ?? null, $b['itemName1'] ?? null, $b['itemCode2'] ?? null, $b['itemName2'] ?? null]),
        ));

        return $out;
    }

    /**
     * Resolve an existing user for a customer being reported on behalf of,
     * checking the local mirror first, then the Auth Service (IdP).
     * Returns null only when the person truly has no account anywhere.
     */
    private function resolveCustomerUser(?string $phone, ?string $email): ?User
    {
        if (! $phone && ! $email) {
            return null;
        }

        // 1. Local users table (includes IdP-mirrored rows).
        // Check both phone and email independently to find existing user.
        $local = User::query()
            ->when($phone, fn ($q) => $q->where('phone_number', $phone))
            ->when($email, fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$email]))
            ->first();

        if ($local) {
            return $local;
        }

        // 2. Ask the IdP — the customer may have logged in before but never
        //    created a local ticket yet.
        $lookup = $this->authServiceClient->findUserByPhoneOrEmail($phone, $email);

        if ($lookup['success'] ?? false) {
            $idpUser = $lookup['user'] ?? [];

            if (! empty($idpUser['uuid'])) {
                // Adopt/mirror the IdP user locally under its canonical uuid.
                return app(UserSyncService::class)
                    ->syncFromAuthServicePayload($idpUser);
            }
        }

        return null;
    }

    /**
     * Generate unique ticket number: TKT-YYYYMMDD-XXXXX
     */
    private function generateTicketNumber(): string
    {
        $prefix = 'TKT';
        $date = now()->format('Ymd');
        $count = Ticket::whereDate('created_at', today())->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $date, $count);
    }
}
