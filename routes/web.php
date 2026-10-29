<?php

use App\Http\Controllers\Auth\AdminSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/dashboard'))->name('home');
Route::get('/login', [AdminSessionController::class, 'create'])->middleware('guest')->name('login');
Route::get('/api/session', [AdminSessionController::class, 'session']);
Route::middleware('guest')->group(function (): void {
    Route::post('/api/login/otp', [AdminSessionController::class, 'requestCode'])->name('login.otp');
    Route::post('/api/login', [AdminSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
    Route::get('/api/user', fn (Request $request) => response()->json(['user' => $request->user()->summary()]));
    Route::post('/api/logout', [AdminSessionController::class, 'destroy'])->name('logout');
});
