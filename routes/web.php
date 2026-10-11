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

Route::middleware(['auth', 'verified', 'role:organizer'])
    ->prefix('organizer')
    ->name('organizer.')
    ->group(function () {
        Route::livewire('events', 'pages::organizer.events.index')->name('events.index');
        Route::livewire('events/create', 'pages::organizer.events.create')->name('events.create');
        Route::livewire('events/{event}/edit', 'pages::organizer.events.edit')->name('events.edit');
    });

require __DIR__.'/settings.php';
