<?php

declare(strict_types=1);

namespace App\Filament\Support\Http;

use App\Filament\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ends a support session and drops the actor back on the platform login.
 */
final class StopImpersonationController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Impersonation::end('manual');

        $root = rtrim((string) config('app.url'), '/');

        return redirect()->away($root.'/platform');
    }
}
