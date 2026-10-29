<?php

use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\EndingCatalogController;
use App\Http\Controllers\EndingGameController;
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
    Route::get('/ending/teams', [EndingCatalogController::class, 'teams'])->name('ending.teams.index');
    Route::post('/ending/teams', [EndingCatalogController::class, 'storeTeam'])->name('ending.teams.store');
    Route::put('/ending/teams/{team}', [EndingCatalogController::class, 'updateTeam'])->whereNumber('team')->name('ending.teams.update');
    Route::get('/ending/leagues', [EndingCatalogController::class, 'leagues'])->name('ending.leagues.index');
    Route::post('/ending/leagues', [EndingCatalogController::class, 'storeLeague'])->name('ending.leagues.store');
    Route::put('/ending/leagues/{league}', [EndingCatalogController::class, 'updateLeague'])->whereNumber('league')->name('ending.leagues.update');
    Route::get('/ending/config', [EndingCatalogController::class, 'configuration'])->name('ending.config');
    Route::post('/ending/config', [EndingCatalogController::class, 'storeConfig'])->name('ending.config.store');
    Route::put('/ending/config/{config}', [EndingCatalogController::class, 'updateConfig'])->whereNumber('config')->name('ending.config.update');
    Route::post('/ending/presets', [EndingCatalogController::class, 'storePreset'])->name('ending.presets.store');
    Route::put('/ending/presets/{type}/{preset}', [EndingCatalogController::class, 'updatePreset'])->whereIn('type', ['1', '2'])->whereNumber('preset')->name('ending.presets.update');
    Route::delete('/ending/presets/{type}/{preset}', [EndingCatalogController::class, 'deletePreset'])->whereIn('type', ['1', '2'])->whereNumber('preset')->name('ending.presets.delete');
    Route::get('/ending/games', [EndingGameController::class, 'index'])->name('ending.games.index');
    Route::get('/ending/history', [EndingGameController::class, 'history'])->name('ending.history');
    Route::get('/ending/games/{game}', [EndingGameController::class, 'show'])->whereNumber('game')->name('ending.games.show');
    Route::get('/ending/games/{game}/cards', [EndingGameController::class, 'cardIndex'])->whereNumber('game')->name('ending.games.cards.index');
    Route::post('/ending/games', [EndingGameController::class, 'store'])->name('ending.games.store');
    Route::scopeBindings()->group(function (): void {
        Route::put('/ending/games/{game}', [EndingGameController::class, 'update'])->whereNumber('game')->name('ending.games.update');
        Route::put('/ending/games/{game}/betting', [EndingGameController::class, 'betting'])->whereNumber('game')->name('ending.games.betting');
        Route::post('/ending/games/{game}/cards', [EndingGameController::class, 'cards'])->whereNumber('game')->name('ending.games.cards');
        Route::put('/ending/games/{game}/bet-amounts/{betAmount}/cards', [EndingGameController::class, 'subtractCards'])->whereNumber(['game', 'betAmount'])->name('ending.games.cards.subtract');
        Route::put('/ending/games/{game}/cards/{card}/betting', [EndingGameController::class, 'cardBetting'])->whereNumber(['game', 'card'])->name('ending.games.card_betting');
        Route::post('/ending/games/{game}/results', [EndingGameController::class, 'result'])->whereNumber('game')->name('ending.games.results.store');
        Route::put('/ending/games/{game}/results/{result}', [EndingGameController::class, 'updateResult'])->whereNumber(['game', 'result'])->name('ending.games.results.update');
        Route::post('/ending/games/{game}/winners/{action}', [EndingGameController::class, 'winner'])->whereNumber('game')->whereIn('action', ['verify', 'unverify', 'approve'])->name('ending.games.winner');
    });
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
    Route::get('/api/user', fn (Request $request) => response()->json(['user' => $request->user()->summary()]));
    Route::post('/api/logout', [AdminSessionController::class, 'destroy'])->name('logout');
});
