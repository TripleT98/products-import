import { DOCUMENT } from '@angular/common';
import { HttpErrorResponse, HttpStatusCode, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { ApiNotificationService } from '../api/api-notification.service';
import { AuthSessionService } from './auth-session.service';
import { TokenType } from '@models/token-type.enum';

export const authInterceptor: HttpInterceptorFn = (request, next) => {
    const document = inject(DOCUMENT);
    let target: URL;
    try {
        target = new URL(request.url, document.baseURI);
    } catch {
        return next(request);
    }
    if (target.origin !== document.location.origin || !target.pathname.startsWith('/api/'))
        return next(request);
    const session = inject(AuthSessionService);
    const notifications = inject(ApiNotificationService);
    const publicEndpoint =
        (target.pathname === '/api/auth/login' && request.method === 'POST') ||
        (target.pathname === '/api/health' && request.method === 'GET');
    const token = publicEndpoint ? null : session.token();
    const outgoing =
        token === null
            ? request
            : request.clone({ setHeaders: { Authorization: `${TokenType.Bearer} ${token}` } });
    return next(outgoing).pipe(
        catchError((error: unknown) => {
            if (error instanceof HttpErrorResponse) {
                if (error.status === HttpStatusCode.Unauthorized && !publicEndpoint) session.unauthorized(token);
                if (error.status >= HttpStatusCode.InternalServerError) notifications.serverFailure();
            }
            return throwError(() => error);
        }),
    );
};
