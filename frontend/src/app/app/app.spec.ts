import { ImportStatus } from '@models/import-status.enum';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, withComponentInputBinding } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { authInterceptor } from '../core/auth/auth.interceptor';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AuthSessionService } from '../core/auth/auth-session.service';
import { loginFixture } from '../../testing/auth-fixture';
import { productFixture } from '../../testing/product-fixture';
import { importFixture } from '../../testing/import-fixture';
import { App } from './app';
import { routes } from '../app.routes';
import { provideProductsStore } from '@store/products/products.providers';

describe('Material shell and lazy routes', () => {
    beforeEach(() => sessionStorage.clear());
    it('renders all direct feature URLs, route parameters and active navigation', async () => {
        TestBed.configureTestingModule({
            imports: [App],
            providers: [
                provideHttpClient(),
                provideHttpClientTesting(),
                provideProductsStore(),
                provideRouter(routes, withComponentInputBinding()),
            ],
        });
        TestBed.inject(AuthSessionService).start(loginFixture());
        const fixture = TestBed.createComponent(App);
        const router = TestBed.inject(Router);

        const element: HTMLElement = fixture.nativeElement;
        const flushProducts = (): void => {
            for (const request of TestBed.inject(HttpTestingController).match((r) =>
                r.url.startsWith('/api/products'),
            )) {
                request.flush(
                    request.request.url === '/api/products'
                        ? { items: [], pagination: { page: 1, limit: 20, total: 0, totalPages: 0 } }
                        : productFixture(Number(request.request.url.split('/').pop())),
                );
            }
            for (const request of TestBed.inject(HttpTestingController).match(
                (r) => r.url.startsWith('/api/imports/') && !r.url.endsWith('/issues'),
            ))
                request.flush(importFixture(ImportStatus.Completed));
            fixture.detectChanges();
            TestBed.tick();
            for (const request of TestBed.inject(HttpTestingController).match((r) =>
                r.url.endsWith('/issues'),
            ))
                request.flush({
                    items: [],
                    pagination: { page: 1, limit: 50, total: 0, totalPages: 0 },
                });
        };
        for (const [url, title] of [
            ['/login', 'Вход'],
            ['/import', 'Импорт товаров'],
            ['/import/123e4567-e89b-42d3-a456-426614174000', 'Результат импорта'],
            ['/products', 'Товары'],
            ['/products/42', 'Карточка товара'],
        ]) {
            await router.navigateByUrl(url);
            fixture.detectChanges();
            TestBed.tick();
            flushProducts();
            await fixture.whenStable();
            expect(element.querySelector(url === '/login' ? 'mat-card-title' : 'h1')?.textContent).toBe(title);
            expect(element.querySelector('mat-card')).not.toBeNull();
            expect(router.url).toBe(url);
            expect(element.querySelector('.route-id')?.textContent ?? '').toBe(
                url.startsWith('/import/')
                    ? '123e4567-e89b-42d3-a456-426614174000'
                    : url === '/products/42'
                      ? '42'
                      : '',
            );
        }
        expect(element.querySelector('nav [aria-current="page"]')?.textContent).toContain('Товары');
        await router.navigateByUrl('/');
        fixture.detectChanges();
        TestBed.tick();
        flushProducts();
        await fixture.whenStable();
        expect(router.url).toBe('/products');
        await router.navigateByUrl('/unknown');
        await fixture.whenStable();
        expect(element.querySelector('h1')?.textContent).toBe('Страница не найдена');
    });

    it('updates the id when a detail component is reused', async () => {
        TestBed.configureTestingModule({
            imports: [App],
            providers: [
                provideHttpClient(),
                provideHttpClientTesting(),
                provideProductsStore(),
                provideRouter(routes, withComponentInputBinding()),
            ],
        });
        TestBed.inject(AuthSessionService).start(loginFixture());
        const fixture = TestBed.createComponent(App);
        const router = TestBed.inject(Router);
        for (const id of ['42', '43']) {
            await router.navigateByUrl('/products/' + id);
            fixture.detectChanges();
            TestBed.tick();
            TestBed.inject(HttpTestingController)
                .expectOne('/api/products/' + id)
                .flush(productFixture(Number(id)));
            await fixture.whenStable();
            const element: HTMLElement = fixture.nativeElement;
            expect(element.querySelector('.route-id')?.textContent).toBe(id);
        }
    });

    it('guards a deep link, handles incorrect login once, then returns after success and logs out', async () => {
        TestBed.configureTestingModule({
            imports: [App],
            providers: [
                provideHttpClient(withInterceptors([authInterceptor])),
                provideHttpClientTesting(),
                provideRouter(routes, withComponentInputBinding()),
            ],
        });
        const fixture = TestBed.createComponent(App);
        const router = TestBed.inject(Router);
        const http = TestBed.inject(HttpTestingController);
        await router.navigateByUrl('/products/42?page=2');
        await fixture.whenStable();
        expect(router.url).toContain('/login?returnUrl=');
        const element: HTMLElement = fixture.nativeElement;
        const fill = (name: string, value: string): void => {
            const input = element.querySelector<HTMLInputElement>(
                `input[formcontrolname="${name}"]`,
            );
            if (!input) throw new Error('Missing login input');
            input.value = value;
            input.dispatchEvent(new Event('input'));
        };
        fill('username', 'fixture');
        fill('password', 'wrong');
        element
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        // A second submit while the first is pending must not create a second HTTP call.
        element
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        http.expectOne('/api/auth/login').flush(
            { private: 'do not render' },
            { status: 500, statusText: 'Error' },
        );
        await fixture.whenStable();
        expect(element.textContent).toContain('Сервис временно недоступен. Попробуйте позже.');
        expect(element.textContent).not.toContain('do not render');
        element
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        http.expectOne('/api/auth/login').flush({}, { status: 401, statusText: 'Unauthorized' });
        await fixture.whenStable();
        expect(element.textContent).toContain('Неверное имя пользователя или пароль.');
        expect(router.url).toContain('/login?returnUrl=');
        fill('password', 'password');
        element
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        http.expectOne('/api/auth/login').flush(loginFixture());
        await vi.waitFor(() => {
            fixture.detectChanges();
            TestBed.tick();
            const requests = http.match('/api/products/42');
            expect(requests).toHaveLength(1);
            requests[0].flush(productFixture());
        });
        await fixture.whenStable();
        expect(router.url).toBe('/products/42?page=2');
        expect(element.querySelector('.route-id')?.textContent).toBe('42');
        TestBed.inject(AuthSessionService).logout();
        await fixture.whenStable();
        expect(router.url).toBe('/login');
        await router.navigateByUrl('/import');
        await fixture.whenStable();
        expect(router.url).toContain('/login?returnUrl=');
        http.verify();
    });
});
