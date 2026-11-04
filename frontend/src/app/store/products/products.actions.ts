import { createAction, props } from '@ngrx/store';
import { ProductPage, ProductQuery } from '@models/product';
import { ProductsLoadError } from '@store/products/products.state';

/** query is a complete request snapshot, not a patch to previous filters. */
export const loadProducts = createAction(
    '[Products] Load Products',
    props<{ query: ProductQuery }>(),
);
export const loadProductsSuccess = createAction(
    '[Products API] Load Products Success',
    props<{ page: ProductPage }>(),
);
export const loadProductsFailure = createAction(
    '[Products API] Load Products Failure',
    props<{ error: ProductsLoadError }>(),
);
