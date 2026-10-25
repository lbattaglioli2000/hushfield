<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing page for visitors. Residents who are already signed in go straight to the town.
 */
final class WelcomeController extends Controller
{
    /** The lines from lang/en/hushfield.php the landing page shows. */
    public const COPY_KEYS = [
        'landing_tagline',
        'landing_intro',
        'landing_walk',
        'landing_home',
        'landing_talk',
        'landing_sign_off',
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('play');
        }

        return Inertia::render('welcome', [
            'copy' => Arr::only((array) __('hushfield'), self::COPY_KEYS),
        ]);
    }
}
