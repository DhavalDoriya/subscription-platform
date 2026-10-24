<?php

use App\Models\User;
use Illuminate\Support\Str;

it('uses uuid primary keys', function () {
    $user = User::factory()->create();

    expect(Str::isUuid($user->id))->toBeTrue();
});

it('soft deletes instead of removing rows', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});
