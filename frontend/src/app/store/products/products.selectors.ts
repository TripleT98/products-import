import { createFeatureSelector, createSelector } from '@ngrx/store';
import { productsFeatureKey, ProductsState } from '@store/products/products.state';

export const selectProductsState = createFeatureSelector<ProductsState>(productsFeatureKey);
export const selectAllProducts = createSelector(selectProductsState, (state) => state.items);
export const selectProductsLoading = createSelector(selectProductsState, (state) => state.loading);
export const selectTotalPages = createSelector(
    selectProductsState,
    (state) => state.pagination.totalPages,
);
export const selectProductsError = createSelector(selectProductsState, (state) => state.error);
export const selectProductsPagination = createSelector(
    selectProductsState,
    (state) => state.pagination,
);
export const selectProductsQuery = createSelector(selectProductsState, (state) => state.query);
