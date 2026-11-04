import { HttpStatusCode } from '@angular/common/http';

export const UPLOAD_ERROR_MESSAGES: Readonly<Partial<Record<HttpStatusCode, string>>> = {
    [HttpStatusCode.Unauthorized]: 'Для загрузки требуется вход.',
    [HttpStatusCode.PayloadTooLarge]: 'Файл превышает допустимый размер на сервере.',
    [HttpStatusCode.UnsupportedMediaType]: 'Сервер не распознал файл как XLSX.',
    [HttpStatusCode.UnprocessableEntity]: 'Книга повреждена или превышает ограничения импорта.',
    [HttpStatusCode.TooManyRequests]: 'Слишком много загрузок. Повторите попытку позже.',
};

export const UPLOAD_UNKNOWN_ERROR = 'Не удалось отправить файл. Повторите попытку.';
export const UPLOAD_NETWORK_ERROR = 'Нет соединения с сервером. Проверьте подключение.';
export const UPLOAD_HTTP_FALLBACK = 'Не удалось отправить файл. Повторите попытку позже.';

export function uploadHttpErrorMessage(status: HttpStatusCode): string {
    return UPLOAD_ERROR_MESSAGES[status] ?? UPLOAD_HTTP_FALLBACK;
}

export function uploadRetryMessage(seconds: string): string {
    return `Слишком много загрузок. Повторите через ${seconds} с.`;
}
