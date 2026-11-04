import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Store } from '@ngrx/store';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductService } from '../../core/api/product.service';
import { ProductPage } from '@models/product';
import { loadProducts, loadProductsFailure, loadProductsSuccess } from '@store/products/products.actions';
import { provideProductsStore } from '@store/products/products.providers';
import { productsReducer } from '@store/products/products.reducer';
import {
    selectAllProducts,
    selectProductsLoading,
    selectTotalPages,
    selectProductsError,
    selectProductsPagination,
    selectProductsQuery,
    selectProductsState,
} from '@store/products/products.selectors';
import { initialProductsState, ProductsState } from '@store/products/products.state';

const page: ProductPage = {
    items: [
        {
            id: 42,
            externalCode: '000042',
            name: 'Чай',
            description: '',
            price: '9999999999999999.99',
            purchasePrice: '0.00',
            discount: '-33.33',
            createdAt: '2026-11-03T00:00:00Z',
            updatedAt: '2026-11-03T00:00:00Z',
        },
    ],
    pagination: { page: 2, limit: 20, total: 21, totalPages: 2 },
};

describe('Products reducer and selectors', () => {
    it('starts idle and ignores unrelated actions', () => {
        expect(productsReducer(undefined, { type: 'unrelated' })).toEqual(initialProductsState);
        expect(productsReducer(initialProductsState, { type: 'unrelated' })).toBe(
            initialProductsState,
        );
    });

    it('starts a fresh query, clears stale data/errors, and preserves input state/action', () => {
        const previous = {
            ...initialProductsState,
            items: page.items,
            pagination: page.pagination,
            error: { status: 500, message: 'old' },
        };
        const snapshot = JSON.stringify(previous);
        const action = loadProducts({ query: { name: 'новый', price_min: '0', page: 3 } });
        const state = productsReducer(previous, action);
        expect(state).toEqual({
            items: [],
            query: { page: 3, limit: 20, name: 'новый', price_min: '0' },
            pagination: { page: 3, limit: 20, total: 0, totalPages: 0 },
            loading: true,
            error: null,
        });
        expect(JSON.stringify(previous)).toBe(snapshot);
        expect(action.query).toEqual({ name: 'новый', price_min: '0', page: 3 });
    });

    it('replaces the server page exactly without local slicing, rounding or sorting', () => {
        const state = productsReducer(
            { ...initialProductsState, loading: true },
            loadProductsSuccess({ page }),
        );
        expect(state.items).toEqual(page.items);
        expect(state.pagination).toEqual(page.pagination);
        expect(state.query).toEqual({ page: 2, limit: 20 });
        expect(state.loading).toBe(false);
        expect(state.error).toBeNull();
    });

    it('supports a successful empty page with totalPages zero', () => {
        const empty: ProductPage = {
            items: [],
            pagination: { page: 1, limit: 20, total: 0, totalPages: 0 },
        };
        const state = productsReducer(initialProductsState, loadProductsSuccess({ page: empty }));
        expect(state.items).toEqual([]);
        expect(state.pagination.totalPages).toBe(0);
        expect(state.error).toBeNull();
    });

    it('ends loading on failure and clears the error when the same query is retried', () => {
        const pending = productsReducer(initialProductsState, loadProducts({ query: { page: 2 } }));
        const failed = productsReducer(
            pending,
            loadProductsFailure({ error: { status: 503, message: 'Ошибка' } }),
        );
        expect(failed.loading).toBe(false);
        expect(failed.query.page).toBe(2);
        const retry = productsReducer(failed, loadProducts({ query: failed.query }));
        expect(retry.loading).toBe(true);
        expect(retry.error).toBeNull();
    });

    it('selects the required public fields from the registered products slice', () => {
        const state = {
            products: { ...initialProductsState, items: page.items, pagination: page.pagination },
        };
        expect(selectAllProducts(state)).toEqual(page.items);
        expect(selectProductsLoading(state)).toBe(false);
        expect(selectTotalPages(state)).toBe(2);
        expect(selectProductsPagination(state)).toEqual(page.pagination);
        expect(selectProductsQuery(state)).toEqual(initialProductsState.query);
        expect(selectProductsError(state)).toBeNull();
    });
});

describe('Registered Store/effect → ProductService → HttpClient boundary', () => {
    let store: Store;
    let http: HttpTestingController;
    let state: ProductsState;
    let stop: { unsubscribe(): void };
    beforeEach(() => {
        TestBed.configureTestingModule({
            providers: [provideHttpClient(), provideHttpClientTesting(), provideProductsStore()],
        });
        store = TestBed.inject(Store);
        http = TestBed.inject(HttpTestingController);
        stop = store.select(selectProductsState).subscribe((value) => {
            state = value;
        });
    });
    afterEach(() => {
        stop.unsubscribe();
        http.verify();
        vi.restoreAllMocks();
    });

    it('dispatches success through the production registration and ProductService with precise filters', () => {
        const service = vi.spyOn(TestBed.inject(ProductService), 'list');
        const query = {
            page: 2,
            name: 'чай & кофе',
            price_min: '0',
            price_max: '9999999999999999.99',
        };
        store.dispatch(loadProducts({ query }));
        expect(state.loading).toBe(true);
        expect(service).toHaveBeenCalledWith(query);
        const request = http.expectOne((r) => r.url === '/api/products');
        expect(request.request.params.get('name')).toBe('чай & кофе');
        expect(request.request.params.get('price_max')).toBe('9999999999999999.99');
        request.flush(page);
        expect(state.items).toEqual(page.items);
        expect(state.loading).toBe(false);
        expect(state.error).toBeNull();
    });

    it.each([400, 401, 500, 503])('survives HTTP %i and succeeds on the next load', (status) => {
        store.dispatch(loadProducts({ query: {} }));
        http.expectOne('/api/products').flush(
            { secret: 'do not persist' },
            { status, statusText: 'Error' },
        );
        expect(state.loading).toBe(false);
        expect(state.error?.status).toBe(status);
        expect(JSON.stringify(state)).not.toContain('do not persist');
        store.dispatch(loadProducts({ query: { page: 2 } }));
        expect(state.loading).toBe(true);
        expect(state.error).toBeNull();
        http.expectOne('/api/products?page=2').flush(page);
        expect(state.items).toEqual(page.items);
        expect(state.loading).toBe(false);
        expect(state.error).toBeNull();
    });

    it('actually cancels the previous HTTP request and keeps only the newest response', () => {
        store.dispatch(loadProducts({ query: { name: 'old' } }));
        const old = http.expectOne('/api/products?name=old');
        store.dispatch(loadProducts({ query: { name: 'new' } }));
        expect(old.cancelled).toBe(true);
        const latest = http.expectOne('/api/products?name=new');
        expect(state.loading).toBe(true);
        expect(state.items).toEqual([]);
        expect(() => old.flush(page)).toThrow();
        latest.flush(page);
        expect(state.query.name).toBe('new');
        expect(state.items).toEqual(page.items);
        expect(state.error).toBeNull();
    });

    it('catches synchronous service failures without ending the effect stream', () => {
        vi.spyOn(TestBed.inject(ProductService), 'list').mockImplementationOnce(() => {
            throw new Error('private detail');
        });
        store.dispatch(loadProducts({ query: {} }));
        expect(state.error?.status).toBeNull();
        expect(JSON.stringify(state)).not.toContain('private detail');
        store.dispatch(loadProducts({ query: {} }));
        http.expectOne('/api/products').flush(page);
        expect(state.items).toEqual(page.items);
        expect(state.error).toBeNull();
    });
});
