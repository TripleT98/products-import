import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loginFixture } from '../../../testing/auth-fixture';
import { ApiNotificationService } from '../api/api-notification.service';
import { AUTH_STORAGE_KEY, AuthSessionService } from './auth-session.service';
import { authInterceptor } from './auth.interceptor';
import { safeReturnUrl } from './return-url';

describe('Session and API-only interceptor', () => {
    const router = {
        url: '/products?page=2',
        navigate: vi.fn().mockResolvedValue(true),
        navigateByUrl: vi.fn().mockResolvedValue(true),
    };
    let session: AuthSessionService;
    let http: HttpTestingController;
    let client: HttpClient;
    beforeEach(() => {
        sessionStorage.clear();
        vi.clearAllMocks();
        TestBed.configureTestingModule({
            providers: [
                { provide: Router, useValue: router },
                provideHttpClient(withInterceptors([authInterceptor])),
                provideHttpClientTesting(),
            ],
        });
        session = TestBed.inject(AuthSessionService);
        http = TestBed.inject(HttpTestingController);
        client = TestBed.inject(HttpClient);
    });
    afterEach(() => {
        http.verify();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('persists only token/user/expiry in sessionStorage and restores a new application session', () => {
        const login = loginFixture();
        session.start(login);
        expect(Object.keys(JSON.parse(sessionStorage.getItem(AUTH_STORAGE_KEY) ?? '{}'))).toEqual([
            'accessToken',
            'expiresAt',
            'user',
        ]);
        TestBed.resetTestingModule();
        TestBed.configureTestingModule({ providers: [{ provide: Router, useValue: router }] });
        expect(TestBed.inject(AuthSessionService).token()).toBe(login.accessToken);
        expect(TestBed.inject(AuthSessionService).user()).toEqual(login.user);
    });

    it('clears malformed storage on refresh', () => {
        TestBed.resetTestingModule();
        sessionStorage.setItem(AUTH_STORAGE_KEY, '{bad JSON');
        TestBed.configureTestingModule({ providers: [{ provide: Router, useValue: router }] });
        expect(TestBed.inject(AuthSessionService).token()).toBeNull();
        expect(sessionStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
    });

    it('rejects an expired cached JWT and a cached deadline beyond JWT exp', () => {
        for (const seconds of [-10, 3600]) {
            TestBed.resetTestingModule();
            const login = loginFixture(seconds);
            sessionStorage.setItem(
                AUTH_STORAGE_KEY,
                JSON.stringify({
                    accessToken: login.accessToken,
                    user: login.user,
                    expiresAt: Date.now() + (seconds < 0 ? -1000 : 7200000),
                }),
            );
            TestBed.configureTestingModule({ providers: [{ provide: Router, useValue: router }] });
            expect(TestBed.inject(AuthSessionService).token()).toBeNull();
            expect(sessionStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
        }
    });

    it('expires an active session and preserves the current return route', async () => {
        vi.useFakeTimers();
        session.start(loginFixture(2));
        await vi.advanceTimersByTimeAsync(2100);
        expect(session.token()).toBeNull();
        expect(session.user()).toBeNull();
        expect(router.navigate).toHaveBeenCalledWith(['/login'], {
            queryParams: { returnUrl: '/products?page=2' },
            replaceUrl: true,
        });
    });

    it('logout clears storage and leaves protected history guarded', () => {
        session.start(loginFixture());
        session.logout();
        expect(sessionStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
        expect(session.token()).toBeNull();
        expect(router.navigateByUrl).toHaveBeenCalledWith('/login', { replaceUrl: true });
    });

    it('fails visibly if storage is denied', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('denied');
        });
        expect(() => session.start(loginFixture())).toThrow();
        expect(session.token()).toBeNull();
    });

    it.each([
        '/api/products',
        '/api/imports/job/issues',
        new URL('/api/products', document.location.href).href,
    ])('adds Bearer only to application API: %s', async (url) => {
        const login = loginFixture();
        session.start(login);
        const response = firstValueFrom(client.get<unknown>(url));
        const req = http.expectOne(url);
        expect(req.request.headers.get('Authorization')).toBe('Bearer ' + login.accessToken);
        req.flush({});
        await response;
    });

    it.each([
        'https://external.test/api/products',
        '//external.test/api/products',
        '/images/test.png',
        '/api-other/products',
        '/api/../images/test.png',
    ])('does not attach credentials or handle external/static errors: %s', async (url) => {
        session.start(loginFixture());
        const response = firstValueFrom(client.get<unknown>(url)).catch((e: unknown) => e);
        const req = http.expectOne(url);
        expect(req.request.headers.has('Authorization')).toBe(false);
        req.flush({}, { status: 401, statusText: 'Unauthorized' });
        await response;
        expect(session.token()).not.toBeNull();
        expect(router.navigate).not.toHaveBeenCalled();
    });

    it('does not redirect or attach a token for an incorrect login', async () => {
        const response = firstValueFrom(client.post<unknown>('/api/auth/login', {})).catch(
            (e: unknown) => e,
        );
        const req = http.expectOne('/api/auth/login');
        expect(req.request.headers.has('Authorization')).toBe(false);
        req.flush({}, { status: 401, statusText: 'Unauthorized' });
        await response;
        expect(router.navigate).not.toHaveBeenCalled();
    });

    it('clears matching revoked sessions on 401 but ignores a late 401 for an older token', async () => {
        const old = loginFixture();
        session.start(old);
        const response = firstValueFrom(client.get<unknown>('/api/products')).catch(
            (e: unknown) => e,
        );
        const req = http.expectOne('/api/products');
        session.start(loginFixture(3600, 'new-signature'));
        req.flush({}, { status: 401, statusText: 'Unauthorized' });
        await response;
        expect(session.token()).not.toBeNull();
        expect(router.navigate).not.toHaveBeenCalled();
        const current = firstValueFrom(client.get<unknown>('/api/products')).catch(
            (e: unknown) => e,
        );
        http.expectOne('/api/products').flush({}, { status: 401, statusText: 'Unauthorized' });
        await current;
        expect(session.token()).toBeNull();
        expect(router.navigate).toHaveBeenCalledTimes(1);
    });

    it('shows a safe server error without clearing credentials or swallowing the HTTP failure', async () => {
        session.start(loginFixture());
        const response = firstValueFrom(client.get<unknown>('/api/products')).catch(
            (e: unknown) => e,
        );
        http.expectOne('/api/products').flush(
            { private: 'never display this' },
            { status: 500, statusText: 'Error' },
        );
        expect(await response).toBeDefined();
        expect(TestBed.inject(ApiNotificationService).message()).toBe(
            'Сервис временно недоступен. Попробуйте позже.',
        );
        expect(session.token()).not.toBeNull();
    });

    it.each([
        '//evil.test',
        'https://evil.test',
        '/login?returnUrl=/login',
        '/products/../../login',
        '/\\evil.test',
    ])('rejects unsafe return URLs: %s', (url) => {
        expect(safeReturnUrl(url)).toBe('/products');
    });
});
