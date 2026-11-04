import { Pagination } from '@models/pagination';
import { Product, ProductQuery } from '@models/product';

export interface ProductsLoadError {
    status: number | null;
    message: string;
}

export interface ProductsState {
    items: Product[];
    pagination: Pagination;
    query: ProductQuery;
    loading: boolean;
    error: ProductsLoadError | null;
}

export const productsFeatureKey = 'products';
export const initialProductsState: ProductsState = {
    items: [],
    pagination: { page: 1, limit: 20, total: 0, totalPages: 0 },
    query: { page: 1, limit: 20 },
    loading: false,
    error: null,
};
