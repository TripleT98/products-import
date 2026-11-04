import { ImportErrorMessage } from '@models/import-error-message.enum';
import { IssueSeverity } from '@models/issue-severity.enum';
import { ViewStateKind } from '@models/view-state-kind.enum';
import { AsyncPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { toObservable } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatTableModule } from '@angular/material/table';
import { MatPaginatorIntl, MatPaginatorModule } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import {
    Subject,
    catchError,
    combineLatest,
    defer,
    distinctUntilChanged,
    map,
    of,
    shareReplay,
    startWith,
    switchMap,
} from 'rxjs';
import { ImportService } from '../../../core/api/import.service';
import { ApiNotificationService } from '../../../core/api/api-notification.service';
import { IssuePage } from '@models/import';

type ReportState = { kind: ViewStateKind.Loading } | { kind: ViewStateKind.Error } | { kind: ViewStateKind.Ready; page: IssuePage };
function reportLabels(): MatPaginatorIntl {
    const intl = new MatPaginatorIntl();
    intl.itemsPerPageLabel = 'Записей на странице';
    intl.nextPageLabel = 'Следующая страница отчёта';
    intl.previousPageLabel = 'Предыдущая страница отчёта';
    intl.firstPageLabel = 'Первая страница отчёта';
    intl.lastPageLabel = 'Последняя страница отчёта';
    intl.getRangeLabel = (page: number, size: number, total: number) =>
        total === 0
            ? '0 из 0'
            : `${page * size + 1}–${Math.min((page + 1) * size, total)} из ${total}`;
    return intl;
}

@Component({
    selector: 'app-import-issues-report',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [AsyncPipe, MatButtonModule, MatTableModule, MatPaginatorModule, MatProgressBarModule],
    providers: [{ provide: MatPaginatorIntl, useFactory: reportLabels }],
    templateUrl: './import-issues-report.html',
    styleUrl: './import-issues-report.css',
})
export class ImportIssuesReport {
    readonly ImportErrorMessage = ImportErrorMessage;
    readonly ViewStateKind = ViewStateKind;
    readonly IssueSeverity = IssueSeverity;
    readonly jobId = input.required<string>();
    readonly revision = input('');
    private readonly imports = inject(ImportService);
    private readonly notices = inject(ApiNotificationService);
    private readonly pages = new Subject<{ page: number; limit: number }>();
    private readonly refresh = new Subject<void>();
    private readonly revision$ = toObservable(this.revision).pipe(distinctUntilChanged());
    readonly columns = ['severity', 'rowNumber', 'externalCode', 'field', 'code', 'message'];
    readonly state$ = toObservable(this.jobId).pipe(
        distinctUntilChanged(),
        switchMap((id) =>
            combineLatest([
                this.revision$,
                this.pages.pipe(startWith({ page: 1, limit: 50 })),
                this.refresh.pipe(startWith(undefined)),
            ]).pipe(
                switchMap(([, query]) =>
                    defer(() => this.imports.issues(id, query)).pipe(
                        map((page): ReportState => ({ kind: ViewStateKind.Ready, page })),
                        catchError(() => of<ReportState>({ kind: ViewStateKind.Error })),
                        startWith({ kind: ViewStateKind.Loading } as const),
                    ),
                ),
            ),
        ),
        shareReplay({ bufferSize: 1, refCount: true }),
    );
    changePage(index: number, size: number): void {
        this.pages.next({ page: index + 1, limit: size });
    }
    retry(): void {
        this.notices.dismiss();
        this.refresh.next();
    }
}
