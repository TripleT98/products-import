import { AsyncPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatTableModule } from '@angular/material/table';
import { MatPaginatorIntl, MatPaginatorModule } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { Store } from '@ngrx/store';
import { combineLatest } from 'rxjs';
import {
    selectAllProducts,
    selectProductsLoading,
    selectTotalPages,
    selectProductsPagination,
    selectProductsError,
} from '@store/products/products.selectors';
import { ProductListController } from '@features/products/product-list-controller.service';

function paginatorLabels(): MatPaginatorIntl {
    const intl = new MatPaginatorIntl();
    intl.itemsPerPageLabel = 'Товаров на странице';
    intl.nextPageLabel = 'Следующая страница';
    intl.previousPageLabel = 'Предыдущая страница';
    intl.firstPageLabel = 'Первая страница';
    intl.lastPageLabel = 'Последняя страница';
    intl.getRangeLabel = (page, size, total) =>
        total === 0
            ? '0 из 0'
            : `${page * size + 1}–${Math.min((page + 1) * size, total)} из ${total}`;
    return intl;
}

@Component({
    selector: 'app-product-list-page',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        AsyncPipe,
        ReactiveFormsModule,
        RouterLink,
        MatCardModule,
        MatFormFieldModule,
        MatInputModule,
        MatButtonModule,
        MatTableModule,
        MatPaginatorModule,
        MatProgressBarModule,
    ],
    providers: [ProductListController, { provide: MatPaginatorIntl, useFactory: paginatorLabels }],
    templateUrl: './product-list-page.html',
    styleUrl: './product-list-page.css',
})
export class ProductListPage {
    readonly controller = inject(ProductListController);
    private readonly store = inject(Store);
    readonly columns = ['externalCode', 'name', 'price', 'discount'];
    readonly vm$ = combineLatest({
        items: this.store.select(selectAllProducts),
        loading: this.store.select(selectProductsLoading),
        totalPages: this.store.select(selectTotalPages),
        pagination: this.store.select(selectProductsPagination),
        error: this.store.select(selectProductsError),
    });
}
