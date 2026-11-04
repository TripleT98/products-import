import { ImportErrorMessage } from '@models/import-error-message.enum';
import { ImportPollErrorMessage } from '@models/import-poll-error-message.enum';
import { ViewStateKind } from '@models/view-state-kind.enum';
import { HttpErrorResponse, HttpStatusCode } from '@angular/common/http';
import { UPLOAD_NETWORK_ERROR, UPLOAD_UNKNOWN_ERROR, uploadHttpErrorMessage, uploadRetryMessage } from '@features/imports/upload-error-messages';
import {
    EMPTY,
    Observable,
    catchError,
    defer,
    expand,
    map,
    of,
    startWith,
    switchMap,
    timer,
} from 'rxjs';
import { ImportService } from '../../core/api/import.service';
import { ImportJob, ImportStatus } from '@models/import';

export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
export function fileProblem(file: File): string | null {
    if (!/\.xlsx$/i.test(file.name)) return ImportErrorMessage.InvalidFileExtension;
    if (file.size === 0) return ImportErrorMessage.EmptyFile;
    return file.size > MAX_UPLOAD_BYTES ? ImportErrorMessage.FileTooLarge : null;
}

export function uploadProblem(error: unknown): string {
    if (!(error instanceof HttpErrorResponse)) return UPLOAD_UNKNOWN_ERROR;
    if (error.status === 0) return UPLOAD_NETWORK_ERROR;
    if (error.status === HttpStatusCode.TooManyRequests) {
        const seconds = error.headers.get('Retry-After');
        if (seconds !== null && /^[0-9]{1,5}$/.test(seconds) && Number(seconds) > 0) {
            return uploadRetryMessage(seconds);
        }
    }
    return uploadHttpErrorMessage(error.status);
}

export function terminal(status: ImportStatus): boolean {
    return status === ImportStatus.Completed || status === ImportStatus.CompletedWithErrors || status === ImportStatus.Failed;
}

export type ImportProgressState =
    | { kind: ViewStateKind.Loading }
    | { kind: ViewStateKind.Ready; job: ImportJob }
    | { kind: ViewStateKind.Error; message: string };

export function pollImport(
    service: ImportService,
    id: string,
    delay = 2000,
): Observable<ImportProgressState> {
    if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(id))
        return of({ kind: ViewStateKind.Error, message: ImportPollErrorMessage.InvalidJobId });
    const request = (): Observable<ImportProgressState> =>
        defer(() => service.status(id)).pipe(
            map((job): ImportProgressState => ({ kind: ViewStateKind.Ready, job })),
            catchError(
                (error: unknown): Observable<ImportProgressState> =>
                    of({
                        kind: ViewStateKind.Error,
                        message:
                            error instanceof HttpErrorResponse && error.status === HttpStatusCode.NotFound
                                ? ImportPollErrorMessage.NotFound
                                : error instanceof HttpErrorResponse && error.status === 0
                                  ? ImportPollErrorMessage.Network
                                  : ImportPollErrorMessage.Fallback,
                    }),
            ),
        );
    // A new request starts only after the previous response and the delay. No timer ticks accumulate.
    return request().pipe(
        expand(
            (state) =>
                state.kind === ViewStateKind.Ready && !terminal(state.job.status)
                    ? timer(delay).pipe(switchMap(() => request()))
                    : EMPTY,
            1,
        ),
        startWith({ kind: ViewStateKind.Loading } as const),
    );
}
