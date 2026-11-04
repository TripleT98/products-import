import { Component, input } from '@angular/core';
import { ImportReportWidget } from '@features/imports/import-report-widget/import-report-widget';

@Component({
    selector: 'app-import-job-page',
    imports: [ImportReportWidget],
    templateUrl: './import-job-page.html',
    styleUrl: './import-job-page.css',
})
export class ImportJobPage {
    readonly jobId = input.required<string>();
}
