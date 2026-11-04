import { TokenType } from '@models/token-type.enum';
import { IssueSeverity } from '@models/issue-severity.enum';
import { ImportStatus } from '@models/import-status.enum';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ApiErrorResponse } from '@models/api-error';
import { LoginResponse } from '@models/auth';
import { ImportJob, IssuePage } from '@models/import';
import { ProductDetail, ProductPage } from '@models/product';
import { AuthService } from './auth.service';
import { ImportService } from './import.service';
import { ProductService } from './product.service';

const product = {
    id: 42,
    externalCode: '000123',
    name: 'Чай',
    description: '',
    price: '9999999999999999.99',
    purchasePrice: '0.00',
    discount: '-33.33',
    createdAt: '2026-11-02T12:00:00Z',
    updatedAt: '2026-11-02T12:00:00Z',
};
const page: ProductPage = {
    items: [product],
    pagination: { page: 2, limit: 20, total: 21, totalPages: 2 },
};
const detail: ProductDetail = {
    ...product,
    attributes: [{ id: 1, key: 'Размер', value: '0' }],
    images: [
        { id: 2, url: 'https://example.test/image.png', path: '/images/fixture.png', position: 0 },
    ],
};
const job: ImportJob = {
    id: '123e4567-e89b-42d3-a456-426614174000',
    status: ImportStatus.Queued,
    totalRows: null,
    processedRows: 0,
    createdProducts: 0,
    updatedProducts: 0,
    failedRows: 0,
    warningCount: 0,
    createdAt: '2026-11-02T12:00:00Z',
    updatedAt: '2026-11-02T12:00:00Z',
    startedAt: null,
    finishedAt: null,
    fatalError: null,
};

describe('HTTP adapters match the backend contract', () => {
    let http: HttpTestingController;
    let products: ProductService;
    let imports: ImportService;
    let auth: AuthService;

    beforeEach(() => {
        TestBed.configureTestingModule({
            providers: [provideHttpClient(), provideHttpClientTesting()],
        });
        http = TestBed.inject(HttpTestingController);
        products = TestBed.inject(ProductService);
        imports = TestBed.inject(ImportService);
        auth = TestBed.inject(AuthService);
    });
    afterEach(() => http.verify());

    it('sends only defined product filters and preserves precise decimal strings and leading zeros', async () => {
        const response = firstValueFrom(
            products.list({
                page: 2,
                limit: 20,
                name: 'чай & кофе+100%',
                price_min: '0',
                price_max: '9999999999999999.99',
            }),
        );
        const req = http.expectOne((r) => r.url === '/api/products');
        expect(req.request.method).toBe('GET');
        expect(req.request.params.keys()).toEqual([
            'page',
            'limit',
            'name',
            'price_min',
            'price_max',
        ]);
        expect(req.request.params.get('name')).toBe('чай & кофе+100%');
        expect(req.request.params.get('price_min')).toBe('0');
        expect(req.request.params.get('price_max')).toBe('9999999999999999.99');
        expect(req.request.params.get('page')).toBe('2');
        expect(req.request.params.get('limit')).toBe('20');
        expect(
            new URL(req.request.urlWithParams, 'https://app.test').searchParams.get('name'),
        ).toBe('чай & кофе+100%');
        req.flush(page);
        expect(await response).toEqual(page);
    });

    it('lets the backend supply pagination defaults and returns an empty page unchanged', async () => {
        const response = firstValueFrom(products.list());
        const req = http.expectOne('/api/products');
        expect(req.request.params.keys()).toEqual([]);
        const empty: ProductPage = {
            items: [],
            pagination: { page: 1, limit: 20, total: 0, totalPages: 0 },
        };
        req.flush(empty);
        expect(await response).toEqual(empty);
    });

    it('reads full product detail without flattening attributes or image metadata', async () => {
        const response = firstValueFrom(products.detail(42));
        const req = http.expectOne('/api/products/42');
        expect(req.request.method).toBe('GET');
        req.flush(detail);
        expect(await response).toEqual(detail);
    });

    it('uploads exactly the file field as multipart without manually setting a boundary or owner', async () => {
        const file = new File(['synthetic fixture'], 'товары.xlsx', {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        });
        const response = firstValueFrom(imports.upload(file));
        const req = http.expectOne('/api/imports');
        expect(req.request.method).toBe('POST');
        expect(req.request.body).toBeInstanceOf(FormData);
        const body: unknown = req.request.body;
        if (!(body instanceof FormData)) throw new Error('Expected FormData');
        expect([...body.keys()]).toEqual(['file']);
        const uploaded = body.get('file');
        expect(uploaded).toBeInstanceOf(File);
        if (!(uploaded instanceof File)) throw new Error('Expected file part');
        expect([uploaded.name, uploaded.size, uploaded.type]).toEqual([
            file.name,
            file.size,
            file.type,
        ]);
        expect(req.request.headers.has('Content-Type')).toBe(false);
        req.flush({ jobId: job.id, status: ImportStatus.Queued }, { status: 202, statusText: 'Accepted' });
        expect(await response).toEqual({ jobId: job.id, status: ImportStatus.Queued });
    });

    it('preserves unknown total and nullable timestamps on queued status', async () => {
        const response = firstValueFrom(imports.status(job.id));
        const req = http.expectOne('/api/imports/' + job.id);
        expect(req.request.method).toBe('GET');
        req.flush(job);
        expect(await response).toEqual(job);
    });

    it('keeps a fatal job distinct from an HTTP error', async () => {
        const response = firstValueFrom(imports.status(job.id));
        const failed: ImportJob = {
            ...job,
            status: ImportStatus.Failed,
            finishedAt: job.updatedAt,
            fatalError: { code: 'WORKBOOK_INVALID', message: 'Некорректная книга.' },
        };
        http.expectOne('/api/imports/' + job.id).flush(failed);
        expect(await response).toEqual(failed);
    });

    it('requests report pages and preserves null system issue context', async () => {
        const response = firstValueFrom(imports.issues(job.id, { page: 3, limit: 50 }));
        const req = http.expectOne('/api/imports/' + job.id + '/issues?page=3&limit=50');
        expect(req.request.method).toBe('GET');
        const issues: IssuePage = {
            items: [
                {
                    id: 4,
                    severity: IssueSeverity.Error,
                    code: 'IMPORT_FAILED',
                    message: 'Ошибка импорта.',
                    rowNumber: null,
                    externalCode: null,
                    field: null,
                },
            ],
            pagination: { page: 3, limit: 50, total: 101, totalPages: 3 },
        };
        req.flush(issues);
        expect(await response).toEqual(issues);
    });

    it('omits undefined issue pagination and encodes the path segment', async () => {
        const response = firstValueFrom(imports.issues('job/unsafe?x=1'));
        const req = http.expectOne('/api/imports/job%2Funsafe%3Fx%3D1/issues');
        expect(req.request.params.keys()).toEqual([]);
        req.flush({ items: [], pagination: { page: 1, limit: 50, total: 0, totalPages: 0 } });
        expect((await response).items).toEqual([]);
    });

    it('posts exact login JSON and returns token metadata without side effects', async () => {
        const credentials = { username: 'fixture', password: 'test-password' };
        const response = firstValueFrom(auth.login(credentials));
        const req = http.expectOne('/api/auth/login');
        expect(req.request.method).toBe('POST');
        expect(req.request.body).toEqual(credentials);
        expect(req.request.headers.has('Authorization')).toBe(false);
        const login: LoginResponse = {
            accessToken: 'synthetic-token',
            tokenType: TokenType.Bearer,
            expiresIn: 3600,
            user: { id: 1, username: 'fixture' },
        };
        req.flush(login);
        expect(await response).toEqual(login);
    });

    it.each([400, 401, 404, 413, 415, 422, 429, 500, 503])(
        'propagates HTTP %i, structured details and Retry-After to callers',
        async (status) => {
            const response = firstValueFrom(imports.status(job.id)).catch(
                (error: unknown) => error,
            );
            const req = http.expectOne('/api/imports/' + job.id);
            const body: ApiErrorResponse = {
                error: {
                    code: 'TEST_ERROR',
                    message: 'Проверка ошибки.',
                    details: [{ field: null, code: 'TEST_ERROR', message: 'Контекст.' }],
                },
            };
            req.flush(body, {
                status,
                statusText: 'Test error',
                headers: { 'Retry-After': '30', 'X-Request-ID': 'fixture-request' },
            });
            const error = await response;
            expect(error).toBeInstanceOf(HttpErrorResponse);
            if (!(error instanceof HttpErrorResponse))
                throw new Error('Expected HttpErrorResponse');
            expect(error.status).toBe(status);
            expect(error.error).toEqual(body);
            expect(error.headers.get('Retry-After')).toBe('30');
            expect(error.headers.get('X-Request-ID')).toBe('fixture-request');
        },
    );
});
