export interface ErrorDetail {
    field: string | null;
    code: string;
    message: string;
}

export interface ApiErrorResponse {
    error: {
        code: string;
        message: string;
        details: ErrorDetail[];
    };
}
