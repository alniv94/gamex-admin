<?php

use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\EndingCatalogController;
use App\Http\Controllers\EndingGameController;
use App\Http\Controllers\PlayerCashierController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SettingsRoleController;
use App\Http\Controllers\SettingsUserController;
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
    Route::get('/settings/users', [SettingsUserController::class, 'index'])->name('settings.users.index');
    Route::get('/settings/users/create', [SettingsUserController::class, 'create'])->name('settings.users.create');
    Route::post('/settings/users', [SettingsUserController::class, 'store'])->name('settings.users.store');
    Route::put('/settings/users/{user}', [SettingsUserController::class, 'update'])->whereNumber('user')->name('settings.users.update');
    Route::put('/settings/users/{user}/status', [SettingsUserController::class, 'status'])->whereNumber('user')->name('settings.users.status');
    Route::post('/settings/roles', [SettingsRoleController::class, 'store'])->name('settings.roles.store');
    Route::put('/settings/roles/{role}', [SettingsRoleController::class, 'update'])->whereNumber('role')->name('settings.roles.update');
    Route::get('/players/{kind}', [PlayerCashierController::class, 'index'])->whereIn('kind', ['cashin', 'cashout'])->name('players.cashier.index');
    Route::get('/players/{kind}/export', [PlayerCashierController::class, 'export'])->whereIn('kind', ['cashin', 'cashout'])->name('players.cashier.export');
    Route::get('/players/{kind}/players', [PlayerCashierController::class, 'players'])->whereIn('kind', ['cashin', 'cashout'])->middleware('throttle:30,1')->name('players.cashier.players');
    Route::post('/players/{kind}/manual', [PlayerCashierController::class, 'manual'])->whereIn('kind', ['cashin', 'cashout'])->middleware('throttle:10,1')->name('players.cashier.manual');
    Route::post('/players/{kind}/{cashier}/{action}', [PlayerCashierController::class, 'evaluate'])->whereIn('kind', ['cashin', 'cashout'])->whereNumber('cashier')->whereIn('action', ['verify', 'reject', 'approve', 'disapprove'])->name('players.cashier.evaluate');
    Route::get('/players/list', [PlayerController::class, 'index'])->name('players.index');
    Route::get('/players/withdrawal_accounts', [PlayerController::class, 'withdrawalAccounts'])->name('players.withdrawal_accounts');
    Route::get('/players/export', [PlayerController::class, 'export'])->name('players.export');
    Route::get('/player/{player}', [PlayerController::class, 'show'])->whereNumber('player')->name('players.show');
    Route::get('/player/{player}/duplicates', [PlayerController::class, 'duplicates'])->whereNumber('player')->name('players.duplicates');
    Route::get('/players/{player}/ndrp', [PlayerController::class, 'ndrp'])->whereNumber('player')->middleware('throttle:30,1')->name('players.ndrp');
    Route::get('/players/{player}/images/{kind}', [PlayerController::class, 'image'])->whereNumber('player')->whereIn('kind', ['selfie', 'valid_id'])->name('players.image');
    Route::post('/players/{player}/verify', [PlayerController::class, 'verify'])->whereNumber('player')->name('players.verify');
    Route::post('/players/{player}/reject', [PlayerController::class, 'reject'])->whereNumber('player')->name('players.reject');
    Route::put('/players/{player}/settings/{setting}', [PlayerController::class, 'setting'])->whereNumber('player')->whereIn('setting', ['betting', 'cashin', 'cashout', 'account'])->name('players.setting');
    Route::post('/players/{player}/fund-accounts/{fund}/{action}', [PlayerController::class, 'fund'])->whereNumber(['player', 'fund'])->whereIn('action', ['verify', 'reject'])->name('players.fund');
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
