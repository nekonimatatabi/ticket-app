<?php

use App\Enums\UserRole;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::events.index')->name('home');
Route::livewire('events/{event}', 'pages::events.show')->name('events.show');

Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('events/{event}/purchase', 'pages::events.purchase')->name('events.purchase');
    Route::livewire('orders', 'pages::orders.index')->name('orders.index');
    Route::livewire('orders/{order}', 'pages::orders.show')->name('orders.show');
    Route::livewire('orders/{order}/complete', 'pages::orders.complete')->name('orders.complete');
    Route::livewire('orders/{order}/cancel', 'pages::orders.cancel')->name('orders.cancel');

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

Route::middleware(['auth', 'verified', 'role:organizer,admin'])
    ->prefix('organizer')
    ->name('organizer.')
    ->group(function () {
        Route::livewire('events/{event}/orders', 'pages::organizer.events.orders')->name('events.orders');
    });

require __DIR__.'/settings.php';
