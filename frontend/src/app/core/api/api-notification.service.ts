import { ApiErrorMessage } from '@models/api-error-message.enum';
import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class ApiNotificationService {
    readonly message = signal<string | null>(null);
    serverFailure(): void {
        this.message.set(ApiErrorMessage.ServerUnavailable);
    }
    dismiss(): void {
        this.message.set(null);
    }
}
