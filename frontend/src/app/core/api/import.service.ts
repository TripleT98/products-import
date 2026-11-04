import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ImportAccepted, ImportJob, IssuePage } from '@models/import';
import { PageQuery } from '@models/pagination';

@Injectable({ providedIn: 'root' })
export class ImportService {
    private readonly http = inject(HttpClient);

    upload(file: File): Observable<ImportAccepted> {
        const body = new FormData();
        body.append('file', file, file.name);
        // The browser supplies multipart Content-Type AND boundary; never set them manually.
        return this.http.post<ImportAccepted>('/api/imports', body);
    }

    status(jobId: string): Observable<ImportJob> {
        return this.http.get<ImportJob>(`/api/imports/${encodeURIComponent(jobId)}`);
    }

    issues(jobId: string, query: PageQuery = {}): Observable<IssuePage> {
        let params = new HttpParams();
        if (query.page !== undefined) params = params.set('page', query.page);
        if (query.limit !== undefined) params = params.set('limit', query.limit);
        return this.http.get<IssuePage>(`/api/imports/${encodeURIComponent(jobId)}/issues`, {
            params,
        });
    }
}
