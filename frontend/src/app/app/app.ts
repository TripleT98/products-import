import { Component, computed, inject } from '@angular/core';
import { AuthSessionService } from '@core/auth/auth-session.service';
import { ApiNotificationService } from '@core/api/api-notification.service';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatButtonModule } from '@angular/material/button';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';

@Component({
    selector: 'app-root',
    imports: [RouterOutlet, RouterLink, RouterLinkActive, MatToolbarModule, MatButtonModule],
    templateUrl: './app.html',
    styleUrl: './app.css',
})
export class App {
    private readonly router = inject(Router);
    readonly session = inject(AuthSessionService);
    readonly notifications = inject(ApiNotificationService);

    private readonly currentUrl = toSignal(
    this.router.events.pipe(
            filter((event) => event instanceof NavigationEnd),
            map((event) => event.urlAfterRedirects),
        ),
        { initialValue: this.router.url },
    );

    readonly isLoginPage = computed(
        () => this.currentUrl().split(/[?#]/)[0] === '/login',
    );
}
