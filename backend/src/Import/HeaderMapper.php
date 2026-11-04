<?php

declare(strict_types=1);

namespace App\Import;

final class HeaderMapper
{
    private const FIELDS = ['Внешний код' => 'externalCode', 'Наименование' => 'name', 'Описание' => 'description',
        'Цена: Цена продажи' => 'price', 'Закупочная цена' => 'purchasePrice'];

    /** @param array<string, CellData> $cells */
    public function map(array $cells): HeaderMap
    {
        $seen = $fields = $attributes = $ignored = [];
        foreach ($cells as $column => $cell) {
            if ($cell->type === 'f') {
                throw $this->invalid();
            }
            $header = $this->trim((string) $cell->value);
            if ($header === '') {
                continue;
            }
            if (isset($seen[$header])) {
                throw $this->invalid();
            }
            $seen[$header] = true;
            if (isset(self::FIELDS[$header])) {
                $fields[self::FIELDS[$header]] = $column;
            } elseif (str_starts_with($header, 'Доп. поле')) {
                $key = $this->trim(substr($header, strlen('Доп. поле')));
                $key = $this->trim(str_starts_with($key, ':') ? substr($key, 1) : $key);
                if ($key === '' || mb_strlen($key, 'UTF-8') > 255 || str_contains($key, "\0") || isset($attributes[$key])) {
                    throw $this->invalid();
                }
                $attributes[$key] = $column;
            } else {
                $ignored[$column] = $header;
            }
        }
        foreach (['externalCode', 'name', 'price', 'purchasePrice'] as $required) {
            if (!isset($fields[$required])) {
                throw $this->invalid();
            }
        }
        return new HeaderMap($fields, $attributes, $ignored);
    }

    private function trim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? throw $this->invalid();
    }

    private function invalid(): WorkbookException
    {
        return new WorkbookException('INVALID_HEADERS', 'Отсутствует обязательный или повторяется нормализованный заголовок/ключ.');
    }
}
