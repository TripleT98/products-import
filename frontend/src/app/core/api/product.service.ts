import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ProductDetail, ProductPage, ProductQuery } from '@models/product';

@Injectable({ providedIn: 'root' })
export class ProductService {
    private readonly http = inject(HttpClient);

    list(query: ProductQuery = {}): Observable<ProductPage> {
        let params = new HttpParams();
        for (const key of ['page', 'limit', 'name', 'price_min', 'price_max'] as const) {
            const value = query[key];
            if (value !== undefined) params = params.set(key, value);
        }
        return this.http.get<ProductPage>('/api/products', { params });
    }

    detail(id: number): Observable<ProductDetail> {
        return this.http.get<ProductDetail>(`/api/products/${encodeURIComponent(String(id))}`);
    }
}
