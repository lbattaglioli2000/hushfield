<?php

namespace App\Http\Controllers\Identity;

use App\Actions\Identity\PassAgeGate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step one of registration. Only birth month and year are asked for, and only
 * the outcome is kept; the account form carries them to CreateNewUser, which checks again.
 */
final class AgeGateController extends Controller
{
    public function __invoke(Request $request, PassAgeGate $passAgeGate): RedirectResponse
    {
        $birth = $passAgeGate(
            $request->only(['birth_month', 'birth_year']),
            $request->cookie((string) config('hushfield.accounts.underage_cookie')),
        );

        $request->session()->put('registration.birth', $birth);

        return to_route('register');
    }
}
