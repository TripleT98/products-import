export interface Pagination {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
}

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

export interface PageQuery {
    page?: number;
    limit?: number;
}
