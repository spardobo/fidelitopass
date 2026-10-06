<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        $business = request()->user()->business()->firstOrFail();

        return view('dashboard', ['business' => $business]);
    })->name('dashboard');

    Route::livewire('business/profile', 'pages::business.profile')->name('business.edit');
    Route::livewire('pass', 'pages::business.pass')->name('business.pass');
    Route::livewire('pass/appearance', 'pages::business.pass')->name('business.pass.appearance');
});

require __DIR__.'/settings.php';
