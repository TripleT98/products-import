import { ProductErrorMessage } from '@models/product-error-message.enum';
import { ViewStateKind } from '@models/view-state-kind.enum';
import { HttpErrorResponse, HttpStatusCode } from '@angular/common/http';
import { Observable, catchError, defer, map, of, startWith } from 'rxjs';
import { ProductService } from '../../core/api/product.service';
import { ProductDetail } from '@models/product';

export type ProductDetailState =
    | { kind: ViewStateKind.Loading }
    | { kind: ViewStateKind.Ready; product: ProductDetail }
    | { kind: ViewStateKind.NotFound }
    | { kind: ViewStateKind.Error; message: string };

export function loadProductDetail(
    service: ProductService,
    id: string,
): Observable<ProductDetailState> {
    if (
        !/^[0-9]+$/.test(id) ||
        !Number.isSafeInteger(Number(id)) ||
        Number(id) < 1 ||
        Number(id) > 2147483647
    )
        return of({ kind: ViewStateKind.NotFound });
    return defer(() => service.detail(Number(id))).pipe(
        map((product): ProductDetailState => ({ kind: ViewStateKind.Ready, product })),
        catchError(
            (error: unknown): Observable<ProductDetailState> =>
                of(
                    error instanceof HttpErrorResponse && error.status === HttpStatusCode.NotFound
                        ? { kind: ViewStateKind.NotFound }
                        : {
                              kind: ViewStateKind.Error,
                              message: ProductErrorMessage.DetailUnavailable,
                          },
                ),
        ),
        startWith({ kind: ViewStateKind.Loading } as const),
    );
}

/** Only local public images are rendered; source URLs never become an img src. */
export function localImagePath(path: string): string | null {
    try {
        const decoded = decodeURIComponent(path);
        return /^\/images\/[^\s?#]+$/.test(path) &&
            !decoded.includes('\\') &&
            !decoded.split('/').some((part) => part === '.' || part === '..')
            ? path
            : null;
    } catch {
        return null;
    }
}
