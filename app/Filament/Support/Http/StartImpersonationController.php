<?php

declare(strict_types=1);

namespace App\Filament\Support\Http;

use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\Impersonation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Landing point of the signed support-access hand-over.
 *
 * Runs inside the academy panel's middleware, so the tenant is already resolved
 * from the hostname by the time we get here; the link's academy id has to match
 * it, otherwise a link for academy A could be replayed against academy B.
 */
final class StartImpersonationController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $academy = TenantContext::get();

        abort_unless($academy instanceof Academy, 404);
        abort_unless((int) $request->query('academy') === (int) $academy->getKey(), 403);

        $nonce = (string) $request->query('nonce');

        abort_if($nonce === '', 403);
        abort_unless(Impersonation::burnNonce($nonce), 403);

        $actor = User::query()->find((int) $request->query('actor'));

        abort_unless($actor instanceof User && $actor->isSuperAdmin(), 403);

        Auth::guard('web')->login($actor);
        $request->session()->regenerate();

        Impersonation::begin($academy, $actor);

        return redirect()->to(Filament::getPanel('panel')->getUrl() ?? url('/panel'));
    }
}
