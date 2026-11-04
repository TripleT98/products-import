import { IssueSeverity } from '@models/issue-severity.enum';
import { ImportStatus } from '@models/import-status.enum';
import { Page } from '@models/pagination';

export { ImportStatus } from '@models/import-status.enum';

export interface ImportAccepted {
    jobId: string;
    status: ImportStatus.Queued;
}

export interface ImportFatalError {
    code: string;
    message: string;
}

export interface ImportJob {
    id: string;
    status: ImportStatus;
    totalRows: number | null;
    processedRows: number;
    createdProducts: number;
    updatedProducts: number;
    failedRows: number;
    warningCount: number;
    createdAt: string;
    startedAt: string | null;
    updatedAt: string;
    finishedAt: string | null;
    fatalError: ImportFatalError | null;
}

export interface ImportIssue {
    id: number;
    severity: IssueSeverity;
    code: string;
    message: string;
    rowNumber: number | null;
    externalCode: string | null;
    field: string | null;
}

export type IssuePage = Page<ImportIssue>;
