<?php

declare(strict_types=1);

namespace App\Import;

final class AttributeExtractor
{
    /** @return list<array{key: string, value: string}> */
    public function extract(WorkbookRow $row): array
    {
        $attributes = [];
        foreach ($row->mapping->attributes as $key => $column) {
            $key = (string) $key;
            if ($key === '' || mb_strlen($key, 'UTF-8') > 255 || str_contains($key, "\0")) {
                throw new WorkbookException('INVALID_HEADERS', 'Недопустимый ключ дополнительного поля.');
            }
            $value = CellNormalizer::text($row->cells[$column] ?? new CellData(), 'attributes.' . $key, true);
            if ($value !== '') {
                $attributes[] = ['key' => $key, 'value' => $value];
            }
        }
        return $attributes;
    }
}
