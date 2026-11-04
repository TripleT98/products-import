import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { convertToParamMap, ActivatedRoute, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { productFixture } from '../../../../testing/product-fixture';
import { ProductDetailPage } from '@features/products/product-detail-page/product-detail-page';
import { localImagePath } from '@features/products/product-detail-state';

describe('Product detail page', () => {
    let http: HttpTestingController;
    beforeEach(() => {
        TestBed.configureTestingModule({
            imports: [ProductDetailPage],
            providers: [
                provideHttpClient(),
                provideHttpClientTesting(),
                provideRouter([]),
                {
                    provide: ActivatedRoute,
                    useValue: {
                        queryParamMap: of(
                            convertToParamMap({ page: '2', name: 'чай', importJobId: 'context' }),
                        ),
                    },
                },
            ],
        });
        http = TestBed.inject(HttpTestingController);
    });
    afterEach(() => http.verify());
    function create(id = '42') {
        const fixture = TestBed.createComponent(ProductDetailPage);
        fixture.componentRef.setInput('id', id);
        fixture.detectChanges();
        TestBed.tick();
        return fixture;
    }

    it('shows loading, exact decimals/all fields, zero attributes, safe text and local images only', () => {
        const fixture = create();
        const el: HTMLElement = fixture.nativeElement;
        expect(el.textContent).toContain('Загрузка товара');
        http.expectOne('/api/products/42').flush(productFixture());
        fixture.detectChanges();
        for (const value of [
            '000042',
            '9999999999999999.99',
            '0.00',
            '-33.33',
            'Размер',
            '0',
            '<script>unsafe()</script>',
            '03:11:2026',
        ])
            expect(el.textContent).toContain(value);
        expect(el.querySelector('script')).toBeNull();
        expect(el.querySelector('img')?.getAttribute('src')).toBe('/images/fixture.png');
        const link = el.querySelector('a');
        expect(link?.getAttribute('href')).toContain('page=2');
        expect(link?.getAttribute('href')).toContain('importJobId=context');
    });

    it('renders missing data and a failed image without a repeated fallback URL request', () => {
        const fixture = create();
        const data = productFixture();
        data.description = '';
        data.attributes = [];
        http.expectOne('/api/products/42').flush(data);
        fixture.detectChanges();
        const el: HTMLElement = fixture.nativeElement;
        expect(el.textContent).toContain('Описание не указано.');
        expect(el.textContent).toContain('Дополнительных атрибутов нет.');
        el.querySelector('img')?.dispatchEvent(new Event('error'));
        fixture.detectChanges();
        expect(el.querySelector('img')).toBeNull();
        expect(el.textContent).toContain('Изображение недоступно');
    });

    it('shows no-image fallback when there are no images', () => {
        const fixture = create();
        const data = productFixture();
        data.images = [];
        http.expectOne('/api/products/42').flush(data);
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain('Изображений нет.');
    });

    it.each(['0', '-1', 'oops', '2147483648'])('rejects invalid route id %s without HTTP', (id) => {
        const fixture = create(id);
        expect((fixture.nativeElement as HTMLElement).textContent).toContain('Товар не найден');
        http.expectNone((r) => r.url.startsWith('/api/products'));
    });

    it('distinguishes 404 from a retryable server error and recovers', () => {
        const fixture = create();
        http.expectOne('/api/products/42').flush({}, { status: 404, statusText: 'Not found' });
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain('Товар не найден');
        fixture.componentRef.setInput('id', '43');
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne('/api/products/43').flush(
            { secret: 'private' },
            { status: 500, statusText: 'Error' },
        );
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('private');
        fixture.componentInstance.retry();
        fixture.detectChanges();
        http.expectOne('/api/products/43').flush(productFixture(43));
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain('Товар 43');
    });

    it('cancels the previous detail on route reuse and displays only the newest product', () => {
        const fixture = create();
        const old = http.expectOne('/api/products/42');
        fixture.componentRef.setInput('id', '43');
        fixture.detectChanges();
        TestBed.tick();
        expect(old.cancelled).toBe(true);
        http.expectOne('/api/products/43').flush(productFixture(43));
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain('Товар 43');
    });

    it.each([
        'https://external.test/image.png',
        '//external.test/image.png',
        '/images/../private',
        '/images/%2e%2e/private',
        '/images/test\\file',
        '/images/%',
        '/uploads/private.xlsx',
    ])('rejects unsafe image path %s', (path) => {
        expect(localImagePath(path)).toBeNull();
    });
});
