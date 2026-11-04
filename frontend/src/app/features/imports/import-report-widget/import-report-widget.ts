import { ViewStateKind } from '@models/view-state-kind.enum';
import { AsyncPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { ActivatedRoute, Params, RouterLink } from '@angular/router';
import { ImportIssuesReport } from '@features/imports/import-issues-report/import-issues-report';
import {
    Subject,
    combineLatest,
    distinctUntilChanged,
    map,
    shareReplay,
    startWith,
    switchMap,
    takeUntil,
} from 'rxjs';
import { ImportService } from '../../../core/api/import.service';
import { ApiNotificationService } from '../../../core/api/api-notification.service';
import { ImportJob, ImportStatus } from '@models/import';
import { pollImport, terminal } from '@features/imports/import-ui-state';

@Component({
    selector: 'app-import-report-widget',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        AsyncPipe,
        MatCardModule,
        MatButtonModule,
        MatProgressBarModule,
        RouterLink,
        ImportIssuesReport,
    ],
    templateUrl: './import-report-widget.html',
    styleUrl: './import-report-widget.css',
})
export class ImportReportWidget {
    readonly ViewStateKind = ViewStateKind;
    readonly jobId = input.required<string>();
    private readonly query = toSignal(
        inject(ActivatedRoute).queryParamMap.pipe(
            map((params) => {
                const result: Params = {};
                for (const key of ['page', 'limit', 'name', 'price_min', 'price_max']) {
                    const value = params.get(key);
                    if (value !== null) result[key] = value;
                }
                return result;
            }),
        ),
    );
    productsQuery(): Params {
        return { ...this.query(), importJobId: this.jobId() };
    }
    private readonly imports = inject(ImportService);
    private readonly notices = inject(ApiNotificationService);
    private readonly refresh = new Subject<void>();
    private readonly stop = new Subject<void>();
    readonly paused = signal(false);
    readonly terminal = terminal;
    readonly labels: Record<ImportStatus, string> = {
        [ImportStatus.Queued]: 'В очереди',
        [ImportStatus.Processing]: 'Обработка',
        [ImportStatus.Completed]: 'Завершено',
        [ImportStatus.CompletedWithErrors]: 'Завершено с замечаниями',
        [ImportStatus.Failed]: 'Импорт не завершён',
    };
    readonly state$ = combineLatest([
        toObservable(this.jobId).pipe(distinctUntilChanged()),
        this.refresh.pipe(startWith(undefined)),
    ]).pipe(
        switchMap(([id]) => {
            this.paused.set(false);
            return pollImport(this.imports, id).pipe(takeUntil(this.stop));
        }),
        shareReplay({ bufferSize: 1, refCount: true }),
    );
    pause(): void {
        this.paused.set(true);
        this.stop.next();
    }
    resume(): void {
        this.notices.dismiss();
        this.refresh.next();
    }
    percent(job: ImportJob): number {
        return job.totalRows === null
            ? 0
            : job.totalRows === 0
              ? terminal(job.status)
                  ? 100
                  : 0
              : Math.min(100, (100 * job.processedRows) / job.totalRows);
    }
}
