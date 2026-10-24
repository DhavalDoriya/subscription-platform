<?php

namespace App\Http\Requests;

/**
 * Standard query params for every listing endpoint: page, per_page, search, sort, direction.
 * Controllers pass an allow-list of sortable columns so callers can never sort by arbitrary columns.
 */
class ListRequest extends ApiFormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }

    /** @param array<int, string> $allowed */
    public function sortColumn(array $allowed, string $default): string
    {
        $sort = $this->validated('sort');

        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    public function sortDirection(): string
    {
        return $this->validated('direction') ?? 'asc';
    }
}
