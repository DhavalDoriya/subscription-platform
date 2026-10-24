<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Auth;

/** Impersonation tokens carry the admin's id in the `imp` claim. */
final class Impersonation
{
    public static function adminId(): ?string
    {
        $guard = Auth::guard('api');

        return $guard->check() ? $guard->payload()->get('imp') : null;
    }

    public static function active(): bool
    {
        return self::adminId() !== null;
    }
}
