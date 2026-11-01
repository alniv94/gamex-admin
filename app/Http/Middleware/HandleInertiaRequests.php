<?php

namespace App\Http\Middleware;

use App\Services\Ending\EndingReports;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $usesInsecureLocalOrigin = app()->isLocal()
            && ! $request->isSecure()
            && ! in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && ! str_ends_with($request->getHost(), '.localhost');

        Inertia::encryptHistory((bool) config('inertia.history.encrypt', false) && ! $usesInsecureLocalOrigin);

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'csrf_token' => fn () => csrf_token(),
            'auth' => ['user' => fn () => $request->user()?->summary()],
            'endingReportNavigation' => fn () => collect(EndingReports::DEFINITIONS)->map(
                fn (array $definition, string $report): array => ['report' => $report, 'title' => $definition[0], 'permission' => $definition[1], 'icon' => $definition[2]],
            )->values()->all(),
        ];
    }
}
