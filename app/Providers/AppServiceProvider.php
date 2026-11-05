<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Pdo\Sqlite;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(PermissionRegistrar $permissions): void
    {
        $this->configureDefaults();

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'sqlite') {
                $pdo = $event->connection->getPdo();
                if ($pdo instanceof Sqlite) {
                    $pdo->createFunction('summary_report_now', fn (): string => now('UTC')->format('Y-m-d H:i:s'));
                }
            }
        });

        // Account eligibility must be checked before Spatie can grant an ability.
        Gate::before(fn (User $user): ?bool => $user->status !== 'Active' || ! $user->profile ? false : null);
        $permissions->registerPermissions(Gate::getFacadeRoot());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            ! app()->runningUnitTests(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
