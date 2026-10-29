<?php

use App\Http\Controllers\CreateCustomerPassController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::landing')->name('home');
Route::livewire('personal/{businessPublicId}/{passPublicId}', 'pages::personal-pass')->name('public.pass');
Route::livewire('join/{public_id}', 'pages::join')->whereUuid('public_id')->name('public.join');
Route::post('join/{public_id}/save', CreateCustomerPassController::class)
    ->whereUuid('public_id')
    ->name('public.join.save');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::business.summary')->name('dashboard');

    Route::livewire('business/profile', 'pages::business.profile')->name('business.edit');
    Route::livewire('pass', 'pages::business.pass')->name('business.pass');
    Route::livewire('pass/appearance', 'pages::business.pass')->name('business.pass.appearance');
    Route::livewire('invite', 'pages::business.invitation')->name('business.invite');
    Route::livewire('promotions/create', 'pages::business.promotion')->name('business.promotions.create');
    Route::livewire('promotions/{promotion:public_id}/edit', 'pages::business.promotion')->name('business.promotions.edit');
});

require __DIR__.'/settings.php';
