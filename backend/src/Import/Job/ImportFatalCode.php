<?php

declare(strict_types=1);

namespace App\Import\Job;

enum ImportFatalCode: string
{
    case InvalidWorkbook = 'WORKBOOK_INVALID';
    case FileUnavailable = 'FILE_UNAVAILABLE';
    case SystemFailure = 'IMPORT_FAILED';
    case PublishFailed = 'PUBLISH_FAILED';

    public function message(): string
    {
        return match ($this) {
            self::InvalidWorkbook => 'Структура книги не поддерживается.',
            self::FileUnavailable => 'Файл импорта недоступен.',
            self::SystemFailure => 'Не удалось завершить импорт.',
            self::PublishFailed => 'Не удалось поставить импорт в очередь.',
        };
    }
}
