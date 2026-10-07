<?php

use App\Models\Category;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function activityTicket(): Ticket
{
    $status = TicketStatus::create(['code' => 'OPEN', 'name' => 'Open']);
    $type = TicketType::create(['code' => 'INCIDENT', 'name' => 'Incident']);
    $category = Category::create(['code' => 'CAT-1', 'name' => 'Kategori']);
    $reporter = User::create([
        'full_name' => 'Reporter',
        'is_active' => true,
        'source' => 'local',
    ]);

    return Ticket::create([
        'ticket_no' => 'TKT-TEST-001',
        'reporter_user_id' => $reporter->id,
        'category_id' => $category->id,
        'ticket_type_id' => $type->id,
        'status_id' => $status->id,
        'subject' => 'Subjek',
        'description' => 'Deskripsi',
    ]);
}

test('logger records an activity row', function () {
    $ticket = activityTicket();

    TicketActivityLogger::record(
        $ticket,
        TicketActivity::TYPE_CREATED,
        $ticket->reporter_user_id,
        'Laporan dibuat.'
    );

    expect(TicketActivity::where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and($ticket->activities()->first()->activity_type)->toBe('CREATED')
        ->and($ticket->activities()->first()->actor->full_name)->toBe('Reporter');
});

test('backfill synthesizes timeline and is idempotent', function () {
    $ticket = activityTicket();

    $this->artisan('tickets:backfill-activities')
        ->expectsOutput('Timeline tersusun untuk 1 tiket.')
        ->assertSuccessful();

    expect($ticket->activities()->count())->toBe(1);

    // Tiket yang sudah punya aktivitas dilewati.
    $this->artisan('tickets:backfill-activities')
        ->expectsOutput('Timeline tersusun untuk 0 tiket.')
        ->assertSuccessful();

    expect($ticket->activities()->count())->toBe(1);
});

test('backfill preserves chronological order', function () {
    $ticket = activityTicket();
    $reviewer = User::create(['full_name' => 'Reviewer', 'is_active' => true, 'source' => 'local']);
    $profile = EmployeeProfile::create([
        'user_id' => $reviewer->id,
        'employee_number' => 'EMP-REV-1',
    ]);

    DB::table('review_logs')->insert([
        'ticket_id' => $ticket->id,
        'reviewer_employee_id' => $profile->id,
        'review_type' => 'INITIAL',
        'decision' => 'ROUTE',
        'reviewed_at' => now()->subHour(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    // Tiket dibuat lebih dulu daripada review-nya.
    $ticket->created_at = now()->subHours(2);
    $ticket->save();

    $this->artisan('tickets:backfill-activities')->assertSuccessful();

    $types = $ticket->activities()->pluck('activity_type')->all();
    expect($types)->toBe(['CREATED', 'REVIEW_ROUTED']);
});
