<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;

final readonly class WorkbookLimits
{
    public function __construct(
        public int $maxFileBytes = 10485760,
        public int $maxUncompressedBytes = 104857600,
        public int $maxZipEntries = 1000,
        public int $maxCompressionRatio = 100,
        public int $maxDataRows = 10000,
        public int $maxRowNumber = 100001,
        public int $chunkRows = 250,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Workbook limits must be positive integers.');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $defaults = new self();
        $values = [];
        foreach (['maxFileBytes' => 'XLSX_MAX_FILE_BYTES', 'maxUncompressedBytes' => 'XLSX_MAX_UNCOMPRESSED_BYTES',
            'maxZipEntries' => 'XLSX_MAX_ZIP_ENTRIES', 'maxCompressionRatio' => 'XLSX_MAX_COMPRESSION_RATIO',
            'maxDataRows' => 'XLSX_MAX_DATA_ROWS', 'maxRowNumber' => 'XLSX_MAX_ROW_NUMBER', 'chunkRows' => 'XLSX_CHUNK_ROWS'] as $property => $variable) {
            $value = getenv($variable);
            if ($value !== false && (!ctype_digit($value) || strlen($value) > 9 || (int) $value < 1)) {
                throw new InvalidArgumentException('Invalid workbook limit configuration.');
            }
            $values[$property] = $value === false ? $defaults->$property : (int) $value;
        }
        return new self(...$values);
    }
}
