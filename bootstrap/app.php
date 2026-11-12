<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Phase 2: an account with a temporary password may only reach the "choose a new password" page.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsurePasswordChanged::class);

        // Phase 3: optional "verify your email first" (config school.registration.require_verification).
        $middleware->appendToGroup('web', \App\Http\Middleware\RequireVerifiedEmail::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // E-mail log: when the mail server refuses or cannot be reached, the e-mail being sent is marked Failed with the reason.
        $exceptions->report(function (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            \App\Services\EmailLogger::failed($e);
        });

        // Phase 3: an old or tampered verification link shows a friendly page instead of a bare 403.
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return redirect()->route('verification.notice')->with('status', 'verification-link-expired');
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
