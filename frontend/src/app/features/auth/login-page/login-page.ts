import { AuthErrorMessage } from '@models/auth-error-message.enum';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse, HttpStatusCode } from '@angular/common/http';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { finalize } from 'rxjs';
import { AuthService } from '../../../core/api/auth.service';
import { AuthSessionService } from '../../../core/auth/auth-session.service';
import { safeReturnUrl } from '../../../core/auth/return-url';

@Component({
    selector: 'app-login-page',
    imports: [
        MatCardModule,
        ReactiveFormsModule,
        MatFormFieldModule,
        MatInputModule,
        MatButtonModule,
    ],
    templateUrl: './login-page.html',
    styleUrl: './login-page.css',
})
export class LoginPage {
    private readonly auth = inject(AuthService);
    private readonly session = inject(AuthSessionService);
    private readonly router = inject(Router);
    private readonly route = inject(ActivatedRoute);
    private readonly destroyRef = inject(DestroyRef);
    readonly form = inject(FormBuilder).nonNullable.group({
        username: ['', Validators.required],
        password: ['', Validators.required],
    });
    readonly busy = signal(false);
    readonly error = signal<string | null>(null);

    submit(): void {
        if (this.busy() || this.form.invalid) return;
        this.busy.set(true);
        this.error.set(null);
        this.auth
            .login(this.form.getRawValue())
            .pipe(
                takeUntilDestroyed(this.destroyRef),
                finalize(() => this.busy.set(false)),
            )
            .subscribe({
                next: (response) => {
                    try {
                        this.session.start(response);
                    } catch {
                        this.error.set(
                            AuthErrorMessage.SessionStorage,
                        );
                        return;
                    }
                    this.form.controls.password.reset('');
                    void this.router.navigateByUrl(
                        safeReturnUrl(this.route.snapshot.queryParamMap.get('returnUrl')),
                        { replaceUrl: true },
                    );
                },
                error: (error: unknown) =>
                    this.error.set(
                        error instanceof HttpErrorResponse && error.status === HttpStatusCode.Unauthorized
                            ? AuthErrorMessage.InvalidCredentials
                            : AuthErrorMessage.LoginFailed,
                    ),
            });
    }
}
