<?php

use App\Http\Middleware\EnsureBusinessOnboarded;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('business/onboarding', 'pages::business.profile')->name('business.create');

    Route::middleware(EnsureBusinessOnboarded::class)->group(function () {
        Route::get('dashboard', function () {
            return view('dashboard', ['business' => request()->user()->business()->firstOrFail()]);
        })->name('dashboard');

        Route::livewire('business/profile', 'pages::business.profile')->name('business.edit');
    });
});

require __DIR__.'/settings.php';
