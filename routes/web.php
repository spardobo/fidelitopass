<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::business.summary')->name('dashboard');

    Route::livewire('business/profile', 'pages::business.profile')->name('business.edit');
    Route::livewire('pass', 'pages::business.pass')->name('business.pass');
    Route::livewire('pass/appearance', 'pages::business.pass')->name('business.pass.appearance');
    Route::livewire('promotions/create', 'pages::business.promotion')->name('business.promotions.create');
    Route::livewire('promotions/{promotion:public_id}/edit', 'pages::business.promotion')->name('business.promotions.edit');
});

require __DIR__.'/settings.php';
