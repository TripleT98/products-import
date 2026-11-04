import { ImageCarousel } from '@features/products/image-carousel/image-carousel';
import { ViewStateKind } from '@models/view-state-kind.enum';
import { AsyncPipe, DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Params, RouterLink } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import {
    Subject,
    combineLatest,
    distinctUntilChanged,
    map,
    shareReplay,
    startWith,
    switchMap,
} from 'rxjs';
import { ProductService } from '../../../core/api/product.service';
import { ApiNotificationService } from '../../../core/api/api-notification.service';
import { loadProductDetail } from '@features/products/product-detail-state';
import { ImportReportWidget } from '@features/imports/import-report-widget/import-report-widget';

@Component({
    selector: 'app-product-detail-page',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        AsyncPipe,
        RouterLink,
        MatCardModule,
        MatButtonModule,
        MatProgressBarModule,
        ImportReportWidget,
        ImageCarousel,
        DatePipe
    ],
    templateUrl: './product-detail-page.html',
    styleUrl: './product-detail-page.css',
})
export class ProductDetailPage {
    readonly ViewStateKind = ViewStateKind;
    readonly id = input.required<string>();
    readonly importJobId = toSignal(
        inject(ActivatedRoute).queryParamMap.pipe(
            map((params) => params.get('importJobId')),
            distinctUntilChanged(),
        ),
    );
    private readonly products = inject(ProductService);
    private readonly notices = inject(ApiNotificationService);
    private readonly reload = new Subject<void>();
    readonly backQuery = toSignal(
        inject(ActivatedRoute).queryParamMap.pipe(
            map((params) => {
                const result: Params = {};
                for (const key of [
                    'page',
                    'limit',
                    'name',
                    'price_min',
                    'price_max',
                    'importJobId',
                ]) {
                    const value = params.get(key);
                    if (value !== null) result[key] = value;
                }
                return result;
            }),
        ),
    );
    readonly state$ = combineLatest([
        toObservable(this.id).pipe(distinctUntilChanged()),
        this.reload.pipe(startWith(undefined)),
    ]).pipe(
        switchMap(([id]) => loadProductDetail(this.products, id)),
        shareReplay({ bufferSize: 1, refCount: true }),
    );

    retry(): void {
        this.notices.dismiss();
        this.reload.next();
    }
}
