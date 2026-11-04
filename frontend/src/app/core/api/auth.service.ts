import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { LoginRequest, LoginResponse } from '@models/auth';

/** HTTP adapter. Session state and logout live in AuthSessionService. */
@Injectable({ providedIn: 'root' })
export class AuthService {
    private readonly http = inject(HttpClient);

    login(credentials: LoginRequest): Observable<LoginResponse> {
        return this.http.post<LoginResponse>('/api/auth/login', credentials);
    }
}
