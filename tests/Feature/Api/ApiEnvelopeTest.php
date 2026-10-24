<?php

use App\Exceptions\ApiException;
use App\Http\Requests\ListRequest;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1/_test')->group(function () {
        Route::get('ok', fn () => ApiResponse::success(['id' => 1]));
        Route::post('created', fn () => ApiResponse::created(['id' => 2]));
        Route::delete('empty', fn () => ApiResponse::noContent());
        Route::get('page', fn (ListRequest $request) => ApiResponse::paginated(
            User::query()->orderBy('email')->paginate($request->perPage()),
            fn (User $user) => ['id' => $user->id, 'email' => $user->email],
        ));
        Route::get('list-params', fn (ListRequest $request) => ApiResponse::success([
            'per_page' => $request->perPage(),
            'search' => $request->search(),
            'sort' => $request->sortColumn(['name', 'email'], 'name'),
            'direction' => $request->sortDirection(),
        ]));
        Route::post('validate', fn (Illuminate\Http\Request $request) => $request->validate(['name' => 'required']));
        Route::get('secure', fn () => ApiResponse::success([]))->middleware('auth:api');
        Route::get('forbidden', fn () => abort(403));
        Route::get('business-rule', fn () => throw new ApiException('Slug is taken.', 409, 'slug_taken', ['slug' => ['Already in use.']]));
        Route::get('boom', fn () => throw new RuntimeException('secret internal detail'));
    });
});

it('wraps single items in data', function () {
    $this->getJson('/api/v1/_test/ok')->assertOk()->assertExactJson(['data' => ['id' => 1]]);
});

it('returns 201 for created and 204 for no content', function () {
    $this->postJson('/api/v1/_test/created')->assertCreated()->assertExactJson(['data' => ['id' => 2]]);
    $this->deleteJson('/api/v1/_test/empty')->assertNoContent();
});

it('has a health endpoint', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
});

it('paginates with data and a fixed meta shape', function () {
    User::factory()->count(3)->create();

    $this->getJson('/api/v1/_test/page?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['id', 'email']], 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
});

it('applies list defaults and caps per_page', function () {
    $this->getJson('/api/v1/_test/list-params')
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.sort', 'name')
        ->assertJsonPath('data.direction', 'asc');

    $this->getJson('/api/v1/_test/list-params?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('only allows whitelisted sort columns and trims search', function () {
    $this->getJson('/api/v1/_test/list-params?sort=password&direction=desc&search=%20bob%20')
        ->assertJsonPath('data.sort', 'name')
        ->assertJsonPath('data.direction', 'desc')
        ->assertJsonPath('data.search', 'bob');

    $this->getJson('/api/v1/_test/list-params?direction=sideways')->assertUnprocessable();
});

it('renders validation errors as message + errors', function () {
    $this->postJson('/api/v1/_test/validate')
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['name']]);
});

it('renders 401, 403, 404 and 405 in the same envelope', function () {
    $this->getJson('/api/v1/_test/secure')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->getJson('/api/v1/_test/forbidden')->assertForbidden()->assertJsonStructure(['message']);
    $this->getJson('/api/v1/nope')->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
    $this->putJson('/api/v1/_test/ok')->assertStatus(405)->assertJsonStructure(['message']);
});

it('never leaks internals on server errors', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server error.']);
});

it('renders ApiException with status, code and errors', function () {
    $this->getJson('/api/v1/_test/business-rule')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Slug is taken.', 'errors' => ['slug' => ['Already in use.']], 'code' => 'slug_taken']);
});
