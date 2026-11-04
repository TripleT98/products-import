<?php

declare(strict_types=1);

namespace App\Image;

enum ImageWarningCode: string
{
    case UrlRejected = 'IMAGE_URL_REJECTED';
    case AddressRejected = 'IMAGE_ADDRESS_REJECTED';
    case DnsFailed = 'IMAGE_DNS_FAILED';
    case HttpError = 'IMAGE_HTTP_ERROR';
    case Timeout = 'IMAGE_TIMEOUT';
    case TooLarge = 'IMAGE_TOO_LARGE';
    case InvalidContent = 'IMAGE_INVALID_CONTENT';
    case RedirectRejected = 'IMAGE_REDIRECT_REJECTED';
    case DownloadFailed = 'IMAGE_DOWNLOAD_FAILED';
    case StorageFailed = 'IMAGE_STORAGE_FAILED';

    public function message(): string
    {
        return match ($this) {
            self::UrlRejected => 'Недопустимый URL изображения.',
            self::AddressRejected => 'Адрес изображения запрещён политикой безопасности.',
            self::DnsFailed => 'Не удалось определить адрес изображения.',
            self::HttpError => 'Сервер изображения вернул ошибку HTTP.',
            self::Timeout => 'Превышено время загрузки изображения.',
            self::TooLarge => 'Изображение превышает допустимый размер.',
            self::InvalidContent => 'Ответ не является поддерживаемым изображением.',
            self::RedirectRejected => 'Недопустимое перенаправление изображения.',
            self::DownloadFailed => 'Не удалось загрузить изображение.',
            self::StorageFailed => 'Не удалось сохранить изображение.',
        };
    }
}
