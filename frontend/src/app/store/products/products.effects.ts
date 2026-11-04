import { ProductErrorMessage } from '@models/product-error-message.enum';
import { HttpErrorResponse, HttpStatusCode } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { catchError, defer, map, of, switchMap } from 'rxjs';
import { ProductService } from '../../core/api/product.service';
import { loadProducts, loadProductsFailure, loadProductsSuccess } from '@store/products/products.actions';
import { ProductsLoadError } from '@store/products/products.state';

function safeLoadError(error: unknown): ProductsLoadError {
    const status = error instanceof HttpErrorResponse ? error.status : null;
    const message =
        status === HttpStatusCode.BadRequest
            ? ProductErrorMessage.InvalidSearch
            : status === HttpStatusCode.Unauthorized
              ? ProductErrorMessage.Unauthorized
              : status === 0
                ? ProductErrorMessage.Network
                : ProductErrorMessage.ListUnavailable;
    return { status, message };
}

@Injectable()
export class ProductsEffects {
    private readonly actions = inject(Actions);
    private readonly products = inject(ProductService);

    readonly loadProducts$ = createEffect(() =>
        this.actions.pipe(
            ofType(loadProducts),
            // Unsubscribe cancels the old HttpClient request as soon as a new query arrives.
            switchMap(({ query }) =>
                defer(() => this.products.list(query)).pipe(
                    map((page) => loadProductsSuccess({ page })),
                    // Keep this inside switchMap so a failure never completes the action stream.
                    catchError((error: unknown) =>
                        of(loadProductsFailure({ error: safeLoadError(error) })),
                    ),
                ),
            ),
        ),
    );
}
