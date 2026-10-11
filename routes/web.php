<?php

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return redirect(match ($request->user()->role) {
            UserRole::Admin => '/admin/events',
            UserRole::Organizer => '/organizer/events',
            UserRole::Customer => '/orders',
        });
    })->name('dashboard');
});

require __DIR__.'/settings.php';
