<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Requests always arrive through the local Traefik proxy
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // Guests are sent home to pick a name, then returned to where they were heading (e.g. a room link)
        $middleware->redirectGuestsTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Hitting a rate limit inside the game: a friendly message where the page shows errors,
        // instead of a bare 429 page
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null;
            }

            $message = __('errors.too_fast');

            return back()->withErrors(['game' => $message, 'lobby' => $message, 'code' => $message, 'name' => $message]);
        });
    })->create();
