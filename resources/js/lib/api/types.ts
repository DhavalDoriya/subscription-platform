/** Mirrors the backend envelope (App\Support\Api\ApiResponse). */
export interface ItemResponse<T> {
    data: T;
}

export interface PageMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface PageResponse<T> {
    data: T[];
    meta: PageMeta;
}

export interface ErrorResponse {
    message: string;
    errors?: Record<string, string[]>;
    code?: string;
}

/** Standard listing params (backend ListRequest). */
export interface ListParams {
    page?: number;
    per_page?: number;
    search?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    [filter: string]: string | number | boolean | undefined;
}
