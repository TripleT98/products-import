import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthSessionService } from './auth-session.service';
import { safeReturnUrl } from './return-url';

export const authGuard: CanActivateFn = (_route, state) =>
    inject(AuthSessionService).token() !== null
        ? true
        : inject(Router).createUrlTree(['/login'], {
              queryParams: { returnUrl: safeReturnUrl(state.url) },
          });
