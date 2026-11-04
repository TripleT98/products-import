import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { finalize } from 'rxjs';
import { ImportService } from '../../../core/api/import.service';
import { ApiNotificationService } from '../../../core/api/api-notification.service';
import { fileProblem, uploadProblem } from '@features/imports/import-ui-state';

@Component({
    selector: 'app-import-page',
    imports: [MatCardModule, MatButtonModule, MatProgressBarModule],
    templateUrl: './import-page.html',
    styleUrl: './import-page.css',
})
export class ImportPage {
    private readonly imports = inject(ImportService);
    private readonly router = inject(Router);
    private readonly destroyRef = inject(DestroyRef);
    private readonly notices = inject(ApiNotificationService);
    private accepted = false;
    readonly file = signal<File | null>(null);
    readonly busy = signal(false);
    readonly error = signal<string | null>(null);
    readonly canUpload = computed(() => {
        const file = this.file();
        return !this.busy() && file !== null && fileProblem(file) === null;
    });

    selectFile(event: Event): void {
        if (this.busy() || !(event.target instanceof HTMLInputElement)) return;
        const file = event.target.files?.item(0) ?? null;
        this.file.set(file);
        this.error.set(file ? fileProblem(file) : null);
    }
    upload(): void {
        const file = this.file();
        if (this.busy() || file === null || fileProblem(file)) return;
        this.error.set(null);
        this.notices.dismiss();
        this.busy.set(true);
        this.imports
            .upload(file)
            .pipe(
                takeUntilDestroyed(this.destroyRef),
                finalize(() => {
                    if (!this.accepted) this.busy.set(false);
                }),
            )
            .subscribe({
                next: (accepted) => {
                    this.accepted = true;
                    void this.router.navigate(['/import', accepted.jobId]);
                },
                error: (error: unknown) => this.error.set(uploadProblem(error)),
            });
    }
}
