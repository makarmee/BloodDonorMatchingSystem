<?php

use App\Http\Controllers\BloodRequestsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationCampsController;
use App\Http\Controllers\DonationsController;
use App\Http\Controllers\DonorPortalController;
use App\Http\Controllers\DonorsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\ScreeningController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/blood-stock', [PublicPortalController::class, 'bloodStock'])->name('public.stock');
Route::get('/blood-camps', [PublicPortalController::class, 'camps'])->name('public.camps.index');
Route::get('/blood-camps/{camp}', [PublicPortalController::class, 'showCamp'])->name('public.camps.show');

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

Route::get('/requests', [BloodRequestsController::class, 'index'])->name('requests.index');
Route::get('/requests/create', [BloodRequestsController::class, 'create'])->name('requests.create');
Route::get('/requests/{request}', [BloodRequestsController::class, 'show'])->name('requests.show');
Route::get('/requests/{request}/edit', [BloodRequestsController::class, 'edit'])->name('requests.edit');

Route::get('/camps', [DonationCampsController::class, 'index'])->name('camps.index');
Route::get('/camps/create', [DonationCampsController::class, 'create'])->name('camps.create');
Route::get('/camps/{camp}', [DonationCampsController::class, 'show'])->name('camps.show');
Route::get('/camps/{camp}/edit', [DonationCampsController::class, 'edit'])->name('camps.edit');

Route::get('/donor-portal', [DonorPortalController::class, 'dashboard'])->name('donor-portal.dashboard');
Route::get('/donor-portal/appointments', [DonorPortalController::class, 'appointments'])->name('donor-portal.appointments.index');
Route::get('/donor-portal/appointments/create', [DonorPortalController::class, 'createAppointment'])->name('donor-portal.appointments.create');
Route::get('/donor-portal/history', [DonorPortalController::class, 'history'])->name('donor-portal.history');
Route::get('/donor-portal/eligibility', [DonorPortalController::class, 'eligibility'])->name('donor-portal.eligibility');
