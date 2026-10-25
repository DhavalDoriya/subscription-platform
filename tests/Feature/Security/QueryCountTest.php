<?php

use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/** Number of queries a request takes, so a list that grows with its rows (N+1) is caught. */
function queriesFor(callable $request): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('FR-L2 the provider payments list does not query per row', function () {
    $url = '/api/v1/provider/payments?per_page=50';
    [, $provider] = providerOwnerSession();
    $make = function (int $n) use ($provider) {
        foreach (range(1, $n) as $i) {
            $payment = Payment::factory()->create(['provider_id' => $provider->id]);
            Commission::factory()->create(['provider_id' => $provider->id, 'payment_id' => $payment->id]);
        }
    };
    $make(2);
    $this->getJson($url)->assertOk(); // warm up: the first request also loads the signed-in user and roles
    $few = queriesFor(fn () => $this->getJson($url)->assertOk());
    $make(10);
    $many = queriesFor(fn () => $this->getJson($url)->assertOk());

    expect($many)->toBe($few);
});

it('FR-L2 the admin commission report does not query per row', function () {
    $url = '/api/v1/admin/commission/report?per_page=50';
    adminSession();
    $make = fn (int $n) => Commission::factory()->count($n)->create(['provider_id' => Provider::factory()]);
    $make(2);
    $this->getJson($url)->assertOk(); // warm up: the first request also loads the signed-in user and roles
    $few = queriesFor(fn () => $this->getJson($url)->assertOk());
    $make(10);
    $many = queriesFor(fn () => $this->getJson($url)->assertOk());

    expect($many)->toBe($few);
});
