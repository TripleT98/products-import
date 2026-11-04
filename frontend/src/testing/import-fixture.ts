import { ImportStatus } from '@models/import-status.enum';
import { ImportJob } from '@models/import';

export function importFixture(status: ImportJob['status'] = ImportStatus.Queued): ImportJob {
    return {
        id: '123e4567-e89b-42d3-a456-426614174000',
        status,
        totalRows: null,
        processedRows: 0,
        createdProducts: 0,
        updatedProducts: 0,
        failedRows: 0,
        warningCount: 0,
        createdAt: '2026-11-03T00:00:00Z',
        updatedAt: '2026-11-03T00:00:00Z',
        startedAt: null,
        finishedAt: null,
        fatalError: null,
    };
}
