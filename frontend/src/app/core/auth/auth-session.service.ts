import { TokenType } from '@models/token-type.enum';
import { DOCUMENT } from '@angular/common';
import { computed, DestroyRef, inject, Injectable, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AuthUser, LoginResponse } from '@models/auth';
import { safeReturnUrl } from './return-url';

export const AUTH_STORAGE_KEY = 'product-import.auth';
interface Session {
    accessToken: string;
    expiresAt: number;
    user: AuthUser;
}
function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
/** exp is only a local expiry hint; the backend verifies signature and identity. */
function tokenExpiry(token: string): number {
    const part = token.split('.')[1];
    if (!part || !/^[A-Za-z0-9_-]+$/.test(part)) throw new Error('Invalid token');
    const payload: unknown = JSON.parse(atob(part.replace(/-/g, '+').replace(/_/g, '/')));
    if (
        !record(payload) ||
        typeof payload['exp'] !== 'number' ||
        !Number.isSafeInteger(payload['exp'])
    )
        throw new Error('Invalid expiry');
    return payload['exp'] * 1000;
}
function isSession(value: unknown): value is Session {
    if (
        !record(value) ||
        typeof value['accessToken'] !== 'string' ||
        typeof value['expiresAt'] !== 'number' ||
        !Number.isFinite(value['expiresAt'])
    )
        return false;
    const user = value['user'];
    return (
        record(user) &&
        typeof user['id'] === 'number' &&
        Number.isSafeInteger(user['id']) &&
        user['id'] > 0 &&
        typeof user['username'] === 'string'
    );
}

@Injectable({ providedIn: 'root' })
export class AuthSessionService {
    private readonly document = inject(DOCUMENT);
    private readonly router = inject(Router);
    private readonly session = signal<Session | null>(null);
    private timer: ReturnType<typeof setTimeout> | undefined;
    readonly user = computed(() => this.session()?.user ?? null);

    constructor() {
        inject(DestroyRef).onDestroy(() => clearTimeout(this.timer));
        try {
            const raw = this.storage().getItem(AUTH_STORAGE_KEY);
            if (raw !== null) {
                const saved: unknown = JSON.parse(raw);
                if (
                    !isSession(saved) ||
                    saved.expiresAt <= Date.now() ||
                    tokenExpiry(saved.accessToken) < saved.expiresAt
                )
                    throw new Error('Invalid session');
                this.session.set(saved);
                this.scheduleExpiry();
            }
        } catch {
            this.clear();
        }
    }

    start(response: LoginResponse): void {
        const expiresAt = Math.min(
            tokenExpiry(response.accessToken),
            Date.now() + response.expiresIn * 1000,
        );
        const saved: Session = {
            accessToken: response.accessToken,
            expiresAt,
            user: response.user,
        };
        if (response.tokenType !== TokenType.Bearer || !isSession(saved) || expiresAt <= Date.now())
            throw new Error('Invalid login response');
        this.storage().setItem(AUTH_STORAGE_KEY, JSON.stringify(saved));
        this.session.set(saved);
        this.scheduleExpiry();
    }

    token(): string | null {
        const saved = this.session();
        if (saved !== null && saved.expiresAt <= Date.now()) {
            this.clear();
            return null;
        }
        return saved?.accessToken ?? null;
    }
    logout(): void {
        this.clear();
        void this.router.navigateByUrl('/login', { replaceUrl: true });
    }
    unauthorized(sentToken: string | null): void {
        // A late response from an old session must not log out a newer successful login.
        if ((this.session()?.accessToken ?? null) === sentToken) this.expire();
    }
    private storage(): Storage {
        const storage = this.document.defaultView?.sessionStorage;
        if (!storage) throw new Error('Session storage unavailable');
        return storage;
    }
    private clear(): void {
        clearTimeout(this.timer);
        this.session.set(null);
        try {
            this.storage().removeItem(AUTH_STORAGE_KEY);
        } catch {
            /* Storage may be denied. */
        }
    }
    private expire(): void {
        const returnUrl = safeReturnUrl(this.router.url);
        this.clear();
        if (this.router.url.split('?')[0] !== '/login')
            void this.router.navigate(['/login'], { queryParams: { returnUrl }, replaceUrl: true });
    }
    private scheduleExpiry(): void {
        clearTimeout(this.timer);
        const saved = this.session();
        if (saved !== null)
            this.timer = setTimeout(() => this.expire(), Math.max(0, saved.expiresAt - Date.now()));
    }
}
