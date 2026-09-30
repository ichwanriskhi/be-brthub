<?php

use App\Http\Controllers\Admin\IdentityRoleController;
use Illuminate\Support\Facades\Route;

/* ------------------------------------------------------------------
|  ADMIN GROUP — requires admin role via 'admin' middleware
| ------------------------------------------------------------------ */
Route::middleware(['auth', 'roles:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Identity Role Management
        Route::get('/identity-roles', [IdentityRoleController::class, 'index'])
            ->name('identity-roles.index');

        Route::get('/identity-roles/{user}/edit', [IdentityRoleController::class, 'edit'])
            ->name('identity-roles.edit');

        Route::put('/identity-roles/{user}', [IdentityRoleController::class, 'update'])
            ->name('identity-roles.update');

        Route::post('/identity-roles/{user}/revoke', [IdentityRoleController::class, 'revoke'])
            ->name('identity-roles.revoke');
    });
