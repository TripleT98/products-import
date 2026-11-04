import { ViewStateKind } from '@models/view-state-kind.enum';
import { ImportStatus } from '@models/import-status.enum';
import { HttpErrorResponse, HttpHeaders, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportService } from '../../core/api/import.service';
import { importFixture } from '../../../testing/import-fixture';
import { ImportProgressState, MAX_UPLOAD_BYTES, fileProblem, pollImport, uploadProblem } from '@features/imports/import-ui-state';
import { ImportPage } from '@features/imports/import-page/import-page';

describe('Import upload UI', () => {
    let http: HttpTestingController;
    const router = { navigate: vi.fn().mockResolvedValue(true) };
    beforeEach(() => {
        vi.clearAllMocks();
        TestBed.configureTestingModule({
            imports: [ImportPage],
            providers: [
                provideHttpClient(),
                provideHttpClientTesting(),
                { provide: Router, useValue: router },
            ],
        });
        http = TestBed.inject(HttpTestingController);
    });
    afterEach(() => http.verify());

    it('rejects wrong extension, empty file and excessive bytes; accepts .XLSX with unknown client MIME', () => {
        expect(fileProblem(new File(['data'], 'data.csv'))).not.toBeNull();
        expect(fileProblem(new File([], 'data.xlsx'))).not.toBeNull();
        expect(
            fileProblem(new File([new Uint8Array(MAX_UPLOAD_BYTES + 1)], 'data.xlsx')),
        ).not.toBeNull();
        expect(fileProblem(new File(['data'], 'data.XLSX', { type: '' }))).toBeNull();
    });

    it('blocks a repeat click while pending and navigates only after the accepted response', () => {
        const fixture = TestBed.createComponent(ImportPage);
        const page = fixture.componentInstance;
        page.file.set(new File(['synthetic'], 'file.xlsx'));
        page.upload();
        page.upload();
        expect(page.busy()).toBe(true);
        expect(page.canUpload()).toBe(false);
        expect(router.navigate).not.toHaveBeenCalled();
        const req = http.expectOne('/api/imports');
        expect(req.request.body).toBeInstanceOf(FormData);
        req.flush(
            { jobId: importFixture().id, status: ImportStatus.Queued },
            { status: 202, statusText: 'Accepted' },
        );
        expect(router.navigate).toHaveBeenCalledWith(['/import', importFixture().id]);
        expect(page.busy()).toBe(true);
        page.upload();
        http.expectNone('/api/imports');
    });

    it.each([400, 401, 413, 415, 422, 429, 503])(
        'shows a safe upload error for HTTP %i and permits manual retry',
        (status) => {
            const fixture = TestBed.createComponent(ImportPage);
            const page = fixture.componentInstance;
            page.file.set(new File(['data'], 'file.xlsx'));
            page.upload();
            http.expectOne('/api/imports').flush(
                { raw: 'private detail' },
                { status, statusText: 'Error', headers: { 'Retry-After': '30' } },
            );
            expect(page.error()).not.toBeNull();
            expect(page.error()).not.toContain('private detail');
            expect(page.canUpload()).toBe(true);
            if (status === 429) expect(page.error()).toContain('30');
            page.upload();
            http.expectOne('/api/imports').flush({ jobId: importFixture().id, status: ImportStatus.Queued });
            expect(page.error()).toBeNull();
        },
    );

    it('cancels an in-flight upload when the page is destroyed', () => {
        const fixture = TestBed.createComponent(ImportPage);
        fixture.componentInstance.file.set(new File(['data'], 'file.xlsx'));
        fixture.componentInstance.upload();
        const req = http.expectOne('/api/imports');
        fixture.destroy();
        expect(req.cancelled).toBe(true);
    });
});

describe('Import status polling lifecycle', () => {
    let http: HttpTestingController;
    let imports: ImportService;
    beforeEach(() => {
        vi.useFakeTimers();
        TestBed.configureTestingModule({
            providers: [provideHttpClient(), provideHttpClientTesting()],
        });
        http = TestBed.inject(HttpTestingController);
        imports = TestBed.inject(ImportService);
    });
    afterEach(() => {
        http.verify();
        vi.useRealTimers();
    });
    const path = '/api/imports/' + importFixture().id;

    it('does not overlap slow requests, delays from response, and shows real counters', () => {
        const values: ImportProgressState[] = [];
        const subscription = pollImport(imports, importFixture().id).subscribe((v) =>
            values.push(v),
        );
        const first = http.expectOne(path);
        vi.advanceTimersByTime(7000);
        http.expectNone(path);
        first.flush(importFixture());
        expect(values.at(-1)).toEqual({ kind: ViewStateKind.Ready, job: importFixture() });
        vi.advanceTimersByTime(1999);
        http.expectNone(path);
        vi.advanceTimersByTime(1);
        const job = {
            ...importFixture(ImportStatus.Processing),
            totalRows: 3,
            processedRows: 2,
            createdProducts: 2,
        };
        http.expectOne(path).flush(job);
        expect(values.at(-1)).toEqual({ kind: ViewStateKind.Ready, job });
        subscription.unsubscribe();
        vi.advanceTimersByTime(6000);
        http.expectNone(path);
    });

    it.each([ImportStatus.Completed, ImportStatus.CompletedWithErrors, ImportStatus.Failed] as const)(
        'stops after terminal %s including a fresh URL lookup',
        (status) => {
            let completed = false;
            pollImport(imports, importFixture().id).subscribe({
                complete: () => {
                    completed = true;
                },
            });
            http.expectOne(path).flush(importFixture(status));
            vi.advanceTimersByTime(6000);
            http.expectNone(path);
            expect(completed).toBe(true);
        },
    );

    it('stops on a network error and allows a new manual polling attempt', () => {
        const values: ImportProgressState[] = [];
        pollImport(imports, importFixture().id).subscribe((v) => values.push(v));
        http.expectOne(path).error(new ProgressEvent('error'));
        expect(values.at(-1)?.kind).toBe('error');
        vi.advanceTimersByTime(6000);
        http.expectNone(path);
        const sub = pollImport(imports, importFixture().id).subscribe();
        http.expectOne(path).flush(importFixture());
        sub.unsubscribe();
    });

    it('cancels a pending HTTP request on unsubscribe and makes no future requests', () => {
        const sub = pollImport(imports, importFixture().id).subscribe();
        const req = http.expectOne(path);
        sub.unsubscribe();
        expect(req.cancelled).toBe(true);
        vi.advanceTimersByTime(6000);
        http.expectNone(path);
    });

    it('does not poll malformed IDs and treats owner/missing 404 as a stopped error', () => {
        const invalid: ImportProgressState[] = [];
        pollImport(imports, 'bad').subscribe((v) => invalid.push(v));
        expect(invalid.at(-1)?.kind).toBe('error');
        http.expectNone(path);
        const values: ImportProgressState[] = [];
        pollImport(imports, importFixture().id).subscribe((v) => values.push(v));
        http.expectOne(path).flush({}, { status: 404, statusText: 'Not found' });
        vi.advanceTimersByTime(6000);
        http.expectNone(path);
        expect(values.at(-1)).toEqual({
            kind: ViewStateKind.Error,
            message: 'Задача не найдена или недоступна.',
        });
    });
});


describe('Safe upload error messages', () => {
    it('accepts only bounded positive Retry-After seconds without reflecting invalid header text', () => {
        for (const value of [null, '0', '-1', '100000', 'Wed, 21 Oct 2026 07:28:00 GMT', '<script>private</script>']) {
            const headers = value === null ? new HttpHeaders() : new HttpHeaders({ 'Retry-After': value });
            expect(uploadProblem(new HttpErrorResponse({ status: 429, headers }))).toBe(
                'Слишком много загрузок. Повторите попытку позже.',
            );
        }
        const headers = new HttpHeaders({ 'Retry-After': '30' });
        expect(uploadProblem(new HttpErrorResponse({ status: 429, headers }))).toBe(
            'Слишком много загрузок. Повторите через 30 с.',
        );
    });

    it('keeps network, unknown HTTP and non-HTTP failures safe and distinct', () => {
        expect(uploadProblem(new HttpErrorResponse({ status: 0 }))).toBe(
            'Нет соединения с сервером. Проверьте подключение.',
        );
        expect(uploadProblem(new HttpErrorResponse({ status: 418, error: { message: 'private' } }))).toBe(
            'Не удалось отправить файл. Повторите попытку позже.',
        );
        expect(uploadProblem(new Error('private'))).toBe(
            'Не удалось отправить файл. Повторите попытку.',
        );
    });
});
