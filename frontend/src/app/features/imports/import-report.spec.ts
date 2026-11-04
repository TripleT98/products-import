import { IssueSeverity } from '@models/issue-severity.enum';
import { ImportStatus } from '@models/import-status.enum';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { BehaviorSubject } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { importFixture } from '../../../testing/import-fixture';
import { productFixture } from '../../../testing/product-fixture';
import { ProductDetailPage } from '@features/products/product-detail-page/product-detail-page';
import { ImportIssuesReport } from '@features/imports/import-issues-report/import-issues-report';
import { ImportReportWidget } from '@features/imports/import-report-widget/import-report-widget';

describe('Selected import report', () => {
    const id = importFixture().id;
    const path = '/api/imports/' + id;
    let http: HttpTestingController;
    let query: BehaviorSubject<ReturnType<typeof convertToParamMap>>;
    const empty = { items: [], pagination: { page: 1, limit: 50, total: 0, totalPages: 0 } };
    beforeEach(() => {
        query = new BehaviorSubject(convertToParamMap({ page: '2', name: 'чай' }));
        TestBed.configureTestingModule({
            imports: [ImportIssuesReport, ImportReportWidget, ProductDetailPage],
            providers: [
                provideHttpClient(),
                provideHttpClientTesting(),
                provideRouter([]),
                { provide: ActivatedRoute, useValue: { queryParamMap: query } },
            ],
        });
        http = TestBed.inject(HttpTestingController);
    });
    afterEach(() => http.verify());
    function report() {
        const fixture = TestBed.createComponent(ImportIssuesReport);
        fixture.componentRef.setInput('jobId', id);
        fixture.detectChanges();
        TestBed.tick();
        return fixture;
    }
    it('renders severity, source row/code/field and message as text including null metadata', () => {
        const fixture = report();
        http.expectOne(path + '/issues?page=1&limit=50').flush({
            items: [
                {
                    id: 1,
                    severity: IssueSeverity.Error,
                    code: 'ROW_INVALID',
                    rowNumber: 3,
                    externalCode: '000003',
                    field: 'price',
                    message: '<img src=x onerror=alert(1)>',
                },
                {
                    id: 2,
                    severity: IssueSeverity.Warning,
                    code: 'IMAGE_FAILED',
                    rowNumber: null,
                    externalCode: null,
                    field: null,
                    message: 'Фото недоступно',
                },
            ],
            pagination: { page: 1, limit: 50, total: 2, totalPages: 1 },
        });
        fixture.detectChanges();
        const el: HTMLElement = fixture.nativeElement;
        for (const value of [
            'Ошибка',
            'Предупреждение',
            '3',
            '000003',
            'price',
            'ROW_INVALID',
            'IMAGE_FAILED',
            '<img src=x onerror=alert(1)>',
            '—',
        ])
            expect(el.textContent).toContain(value);
        expect(el.querySelector('img')).toBeNull();
        expect(el.querySelectorAll('tr.mat-mdc-row').length).toBe(2);
    });
    it('requests server pages and resets pagination on a different job', () => {
        const fixture = report();
        http.expectOne(path + '/issues?page=1&limit=50').flush(empty);
        fixture.componentInstance.changePage(1, 10);
        http.expectOne(path + '/issues?page=2&limit=10').flush({
            ...empty,
            pagination: { page: 2, limit: 10, total: 13, totalPages: 2 },
        });
        const next = '123e4567-e89b-42d3-a456-426614174099';
        fixture.componentRef.setInput('jobId', next);
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne('/api/imports/' + next + '/issues?page=1&limit=50').flush(empty);
    });
    it('cancels stale pages and in-flight requests on destroy', () => {
        const fixture = report();
        const first = http.expectOne(path + '/issues?page=1&limit=50');
        fixture.componentInstance.changePage(1, 10);
        expect(first.cancelled).toBe(true);
        const second = http.expectOne(path + '/issues?page=2&limit=10');
        fixture.destroy();
        expect(second.cancelled).toBe(true);
    });
    it('shows a safe independent report failure and retries the same query', () => {
        const fixture = report();
        http.expectOne(path + '/issues?page=1&limit=50').flush(
            { secret: 'private stack' },
            { status: 503, statusText: 'Unavailable' },
        );
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain(
            'Не удалось получить отчёт',
        );
        expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('private stack');
        fixture.componentInstance.retry();
        http.expectOne(path + '/issues?page=1&limit=50').flush(empty);
        fixture.detectChanges();
        expect((fixture.nativeElement as HTMLElement).textContent).toContain(
            'Ошибок и предупреждений нет.',
        );
    });
    it('refreshes issues on changed status/counters without its own polling timer', () => {
        const fixture = report();
        http.expectOne(path + '/issues?page=1&limit=50').flush(empty);
        fixture.componentRef.setInput('revision', 'completed_with_errors:1:1');
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne(path + '/issues?page=1&limit=50').flush(empty);
        fixture.componentRef.setInput('revision', 'completed_with_errors:1:1');
        fixture.detectChanges();
        TestBed.tick();
        http.expectNone((r) => r.url.endsWith('/issues'));
    });
    it('uses the shared terminal widget with explicit context and a context-preserving catalog link', () => {
        const fixture = TestBed.createComponent(ImportReportWidget);
        fixture.componentRef.setInput('jobId', id);
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne(path).flush(importFixture(ImportStatus.Completed));
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne(path + '/issues?page=1&limit=50').flush(empty);
        fixture.detectChanges();
        const el: HTMLElement = fixture.nativeElement;
        expect(el.textContent).toContain('Отчёт выбранного импорта');
        expect(el.textContent).toContain('Обновление статуса завершено.');
        const href = el.querySelector('a')?.getAttribute('href');
        expect(href).toContain('importJobId=' + id);
        expect(href).toContain('page=2');
        expect(href).toContain('name=');
    });
    it('does not create a widget or import requests on a card without context', () => {
        const fixture = TestBed.createComponent(ProductDetailPage);
        fixture.componentRef.setInput('id', '42');
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne('/api/products/42').flush(productFixture());
        fixture.detectChanges();
        expect(
            (fixture.nativeElement as HTMLElement).querySelector('app-import-report-widget'),
        ).toBeNull();
        http.expectNone((r) => r.url.startsWith('/api/imports'));
    });
    it.each(['missing', 'foreign'])('keeps a product visible when its selected job is %s', () => {
        query.next(convertToParamMap({ importJobId: id }));
        const fixture = TestBed.createComponent(ProductDetailPage);
        fixture.componentRef.setInput('id', '42');
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne('/api/products/42').flush(productFixture());
        http.expectOne(path).flush({}, { status: 404, statusText: 'Not found' });
        fixture.detectChanges();
        const el: HTMLElement = fixture.nativeElement;
        expect(el.textContent).toContain(productFixture().name);
        expect(el.textContent).toContain('Задача не найдена или недоступна.');
        http.expectNone((r) => r.url.endsWith('/issues'));
    });
    it('destroys the active widget when query context is removed on a reused card', () => {
        query.next(convertToParamMap({ importJobId: id }));
        const fixture = TestBed.createComponent(ProductDetailPage);
        fixture.componentRef.setInput('id', '42');
        fixture.detectChanges();
        TestBed.tick();
        http.expectOne('/api/products/42').flush(productFixture());
        const pending = http.expectOne(path);
        query.next(convertToParamMap({}));
        fixture.detectChanges();
        TestBed.tick();
        expect(pending.cancelled).toBe(true);
        expect(
            (fixture.nativeElement as HTMLElement).querySelector('app-import-report-widget'),
        ).toBeNull();
    });
});
