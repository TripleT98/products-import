import { createReducer, on } from '@ngrx/store';
import { loadProducts, loadProductsFailure, loadProductsSuccess } from '@store/products/products.actions';
import { initialProductsState } from '@store/products/products.state';

export const productsReducer = createReducer(
    initialProductsState,
    on(loadProducts, (state, { query }) => ({
        ...state,
        query: { page: 1, limit: 20, ...query },
        items: [],
        pagination: { page: query.page ?? 1, limit: query.limit ?? 20, total: 0, totalPages: 0 },
        loading: true,
        error: null,
    })),
    on(loadProductsSuccess, (state, { page }) => ({
        ...state,
        items: page.items,
        pagination: page.pagination,
        query: { ...state.query, page: page.pagination.page, limit: page.pagination.limit },
        loading: false,
        error: null,
    })),
    on(loadProductsFailure, (state, { error }) => ({ ...state, loading: false, error })),
);
