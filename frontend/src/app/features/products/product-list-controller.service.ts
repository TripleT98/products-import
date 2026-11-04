import { DestroyRef, inject, Injectable, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder } from '@angular/forms';
import { ActivatedRoute, Params, Router } from '@angular/router';
import { Store } from '@ngrx/store';
import { debounceTime, distinctUntilChanged, switchMap, tap } from 'rxjs';
import { ProductQuery } from '@models/product';
import { loadProducts } from '@store/products/products.actions';
import { ApiNotificationService } from '../../core/api/api-notification.service';
import { filterError, ProductFilters, readProductQuery } from '@features/products/product-list-query';

/** Route/form orchestration only; all HTTP remains in ProductService through NgRx effects. */
@Injectable()
export class ProductListController {
    private readonly route = inject(ActivatedRoute);
    private readonly router = inject(Router);
    private readonly store = inject(Store);
    private readonly notices = inject(ApiNotificationService);
    private readonly current = signal<ProductQuery>({ page: 1, limit: 20 });
    private lastRequest: string | undefined;
    readonly error = signal<string | null>(null);
    readonly form = inject(FormBuilder).nonNullable.group({
        name: '',
        price_min: '',
        price_max: '',
    });

    constructor() {
        this.route.queryParamMap
            .pipe(
                tap((params) => {
                    const parsed = readProductQuery(params);
                    this.form.setValue(parsed.filters, { emitEvent: false });
                    this.error.set(parsed.error);
                    this.current.set(parsed.query);
                    const key = JSON.stringify(parsed.query);
                    if (parsed.error) this.lastRequest = undefined;
                    else if (key !== this.lastRequest) {
                        this.lastRequest = key;
                        this.store.dispatch(loadProducts({ query: parsed.query }));
                    }
                }),
                // Back/forward navigation cancels pending debounced form edits before they can overwrite the URL.
                switchMap(() =>
                    this.form.valueChanges.pipe(
                        debounceTime(300),
                        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
                    ),
                ),
                takeUntilDestroyed(inject(DestroyRef)),
            )
            .subscribe(() => this.applyFilters());
    }

    applyFilters(): void {
        const filters: ProductFilters = this.form.getRawValue();
        const error = filterError(filters);
        this.error.set(error);
        if (error === null)
            this.navigate({
                page: 1,
                limit: this.validLimit(),
                name: filters.name.trim() || null,
                price_min: filters.price_min || null,
                price_max: filters.price_max || null,
            });
    }

    changePage(pageIndex: number, pageSize: number): void {
        if (this.error() === null)
            this.navigate({
                page: pageSize === this.validLimit() ? pageIndex + 1 : 1,
                limit: pageSize,
            });
    }

    reset(): void {
        this.form.setValue({ name: '', price_min: '', price_max: '' }, { emitEvent: false });
        this.error.set(null);
        this.navigate({
            page: 1,
            limit: this.validLimit(),
            name: null,
            price_min: null,
            price_max: null,
        });
    }

    retry(): void {
        if (this.error() === null) {
            this.notices.dismiss();
            this.store.dispatch(loadProducts({ query: this.current() }));
        }
    }

    linkQuery(): Params {
        const params = this.route.snapshot.queryParamMap;
        const result: Params = {};
        for (const key of ['page', 'limit', 'name', 'price_min', 'price_max', 'importJobId']) {
            const value = params.get(key);
            if (value !== null) result[key] = value;
        }
        return result;
    }

    openProduct(id: number): void {
        void this.router.navigate(['/products', id], { queryParams: this.linkQuery() });
    }
    private validLimit(): number {
        const limit = this.current().limit ?? 20;
        return limit >= 1 && limit <= 100 ? limit : 20;
    }
    private navigate(params: Params): void {
        void this.router.navigate([], {
            relativeTo: this.route,
            queryParams: params,
            queryParamsHandling: 'merge',
        });
    }
}
