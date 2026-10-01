<?php

use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetDatabaseUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'db.user' => SetDatabaseUser::class,
            'tenant' => ResolveTenant::class,
            'tenant.role' => RequireTenantRole::class,
        ]);

        // Route-model binding must run after the tenant is set, so that a
        // {team} from another business is simply not found.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            before: ResolveTenant::class,
            prepend: SetDatabaseUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
