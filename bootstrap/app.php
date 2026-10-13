<?php

use App\Http\Middleware\RequestLogContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestLogContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): bool {
            Log::error('application.unexpected_exception', [
                'exception_class' => $exception::class,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'exception_frames' => array_map(
                    static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                    $exception->getTrace(),
                ),
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
