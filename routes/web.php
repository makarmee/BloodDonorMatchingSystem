<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationsController;
use App\Http\Controllers\DonorsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ScreeningController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');
Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');

Route::get('/dashboard', DashboardController::class)->name('dashboard');
Route::get('/inventory', InventoryController::class)->name('inventory.index');

Route::get('/donors', [DonorsController::class, 'index'])->name('donors.index');
Route::get('/donors/create', [DonorsController::class, 'create'])->name('donors.create');
Route::get('/donors/{donor}', [DonorsController::class, 'show'])->name('donors.show');
Route::get('/donors/{donor}/edit', [DonorsController::class, 'edit'])->name('donors.edit');

Route::get('/donations', [DonationsController::class, 'index'])->name('donations.index');
Route::get('/donations/create', [DonationsController::class, 'create'])->name('donations.create');
Route::get('/donations/{donation}', [DonationsController::class, 'show'])->name('donations.show');
Route::get('/donations/{donation}/edit', [DonationsController::class, 'edit'])->name('donations.edit');

Route::get('/screening', [ScreeningController::class, 'index'])->name('screening.index');
Route::get('/screening/create', [ScreeningController::class, 'create'])->name('screening.create');
Route::get('/screening/{screening}', [ScreeningController::class, 'show'])->name('screening.show');
