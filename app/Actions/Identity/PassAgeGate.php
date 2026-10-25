<?php

namespace App\Actions\Identity;

use App\Domain\Identity\AgePolicy;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Refuses anyone under the minimum age, and remembers the refusal in a
 * short-lived cookie so the same browser can't immediately try another year.
 */
final readonly class PassAgeGate
{
    public function __construct(private AgePolicy $ages) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{birth_month: int, birth_year: int}
     */
    public function __invoke(array $input, ?string $refusalCookie): array
    {
        $cookie = (string) config('hushfield.accounts.underage_cookie');

        if ($refusalCookie !== null) {
            throw ValidationException::withMessages(['underage' => __('hushfield.underage')]);
        }

        $currentYear = (int) now((string) config('hushfield.timezone'))->year;

        /** @var array{birth_month: int|string, birth_year: int|string} $validated */
        $validated = Validator::make($input, [
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['required', 'integer', 'between:'.($currentYear - 120).','.$currentYear],
        ], [], ['birth_month' => 'birth month', 'birth_year' => 'birth year'])->validate();

        $month = (int) $validated['birth_month'];
        $year = (int) $validated['birth_year'];

        if (! $this->ages->meetsMinimumAge($month, $year)) {
            Cookie::queue($cookie, '1', (int) config('hushfield.accounts.underage_cookie_minutes'));

            throw ValidationException::withMessages(['underage' => __('hushfield.underage')]);
        }

        return ['birth_month' => $month, 'birth_year' => $year];
    }
}
