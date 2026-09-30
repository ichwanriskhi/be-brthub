<?php

use App\Http\Controllers\Admin\IdentityRoleController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\PasswordLinkController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HandlerController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\SapController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketInteractionController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // OTP endpoints
    Route::post('otp/request', [AuthController::class, 'requestOtp']);
    Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
    Route::post('token/refresh', [AuthController::class, 'refreshToken']);

    // Google OAuth endpoints
    Route::get('google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('google/callback', [AuthController::class, 'handleGoogleCallback']);

    // Protected endpoints (require valid access token from Auth Service)
    Route::middleware('auth.authservice')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
        Route::get('identity-status', [AuthController::class, 'identityStatus']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Ticket endpoints
        Route::apiResource('tickets', TicketController::class);
        Route::post('tickets/{id}/review', [TicketController::class, 'submitReview']);

        // Percakapan (obrolan) tiket
        Route::get('tickets/{id}/interactions', [TicketInteractionController::class, 'index']);
        Route::post('tickets/{id}/interactions', [TicketInteractionController::class, 'store']);
        Route::delete('tickets/{id}/interactions/{interaction}', [TicketInteractionController::class, 'destroy']);

        // Approval endpoints — approver adalah posisi, bukan role
        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::post('approvals/{id}/decide', [ApprovalController::class, 'decide']);

        // Unit Teknis — departemen penerima tiket setelah approval awal
        Route::get('unit/tickets', [UnitController::class, 'queue']);
        Route::get('unit/tickets/history', [UnitController::class, 'history']);
        Route::get('unit/tickets/{id}', [UnitController::class, 'show']);
        Route::post('unit/tickets/{id}/assign-handler', [UnitController::class, 'assignHandler']);

        // Handler — pegawai yang mengerjakan tiket (assignment dari unit)
        Route::get('handler/tickets', [HandlerController::class, 'index']);
        Route::get('handler/tickets/{id}', [HandlerController::class, 'show']);
        Route::post('handler/tickets/{id}/progress', [HandlerController::class, 'storeProgress']);
        Route::post('handler/tickets/{id}/resolution', [HandlerController::class, 'submitResolution']);

        // SAP integration
        Route::get('sap/items', [SapController::class, 'searchItems']);
    });
});

// Admin endpoints for BRTHub app admin (using client credentials via AuthServiceClient)
Route::middleware('auth.authservice')->prefix('admin')->group(function () {
    Route::post('users/{userId}/setup-password-link', [PasswordLinkController::class, 'createSetupLink']);
    Route::post('users/{userId}/reset-password-link', [PasswordLinkController::class, 'createResetLink']);

    // Identity & role management
    Route::get('users/{user}/roles', [IdentityRoleController::class, 'showRoles']);

    // Employee profile management
    Route::get('employee-profiles', [IdentityRoleController::class, 'indexEmployeeProfiles']);
    Route::get('employee-profiles/{id}', [IdentityRoleController::class, 'showEmployeeProfile']);
    Route::post('employee-profiles', [IdentityRoleController::class, 'storeEmployeeProfile']);
    Route::put('employee-profiles/{id}', [IdentityRoleController::class, 'updateEmployeeProfile']);
    Route::delete('employee-profiles/{id}', [IdentityRoleController::class, 'destroyEmployeeProfile']);
    Route::get('customers', [CustomerController::class, 'index']);
});

// Master data endpoints (public access for dropdowns)
Route::prefix('master')->group(function () {
    Route::get('departments', [MasterDataController::class, 'departments']);
    Route::get('employees', [MasterDataController::class, 'employees']);
    Route::get('positions', [MasterDataController::class, 'positions']);
    Route::get('categories', [MasterDataController::class, 'categories']);
    Route::get('products', [MasterDataController::class, 'products']);
    Route::get('priorities', [MasterDataController::class, 'priorities']);
    Route::get('ticket-types', [MasterDataController::class, 'ticketTypes']);
    Route::get('statuses', [MasterDataController::class, 'statuses']);
    Route::get('all', [MasterDataController::class, 'all']);
});

// Admin master data CRUD (protected)
Route::middleware('auth.authservice')->prefix('admin')->group(function () {
    Route::post('master/{type}', [MasterDataController::class, 'store']);
    Route::put('master/{type}/{id}', [MasterDataController::class, 'update']);
    Route::delete('master/{type}/{id}', [MasterDataController::class, 'destroy']);
});
