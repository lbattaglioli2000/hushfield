<?php

namespace App\Domain\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The short-lived cookie that remembers this browser was refused at the age gate.
 */
final class AgeGateCookie
{
    public function isSet(Request $request): bool
    {
        return $request->hasCookie($this->name());
    }

    public function remember(): void
    {
        Cookie::queue($this->name(), '1', (int) config('hushfield.accounts.underage_cookie_minutes'));
    }

    private function name(): string
    {
        return (string) config('hushfield.accounts.underage_cookie');
    }
}
