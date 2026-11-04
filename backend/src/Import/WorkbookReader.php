<?php

declare(strict_types=1);

namespace App\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

final class WorkbookReader
{
    public function __construct(private WorkbookLimits $limits = new WorkbookLimits())
    {
    }

    /** Upload resource/container checks only. Business headers and sheet selection remain worker decisions. */
    public function validateUpload(string $path): void
    {
        $package = XlsxPackage::open($path, $this->limits);
        try {
            $count = 0;
            foreach ($package->sheets() as $name => $part) {
                foreach ($this->sheetRows($package, $path, (string) $name, $part) as $row) {
                    if ($row['number'] > 1 && !$this->isEmpty($row['cells']) && ++$count > $this->limits->maxDataRows) {
                        throw WorkbookException::limit();
                    }
                }
            }
        } catch (WorkbookException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw WorkbookException::invalid($error);
        } finally {
            $package->close();
        }
    }

    public function open(string $path): Workbook
    {
        $package = XlsxPackage::open($path, $this->limits);
        try {
            $sheets = $package->sheets();
            if ((new Xlsx())->listWorksheetNames($path) !== array_map('strval', array_keys($sheets))) {
                throw WorkbookException::invalid();
            }
            $selected = null;
            $header = [];
            $count = 0;
            foreach ($sheets as $name => $part) {
                $name = (string) $name;
                $nonempty = false;
                foreach ($this->sheetRows($package, $path, $name, $part) as $row) {
                    if ($row['number'] === 1 && !$nonempty) {
                        $header = $row['cells'];
                    }
                    if (!$this->isEmpty($row['cells'])) {
                        if ($selected !== null && $selected !== $name) {
                            throw WorkbookException::invalid();
                        }
                        $selected = $name;
                        $nonempty = true;
                        if ($row['number'] > 1 && ++$count > $this->limits->maxDataRows) {
                            throw WorkbookException::limit();
                        }
                    }
                }
                if ($selected === $name) {
                    // Empty later sheets must not replace the selected header.
                    $selectedHeader = $header;
                }
            }
            if ($selected === null) {
                throw WorkbookException::invalid();
            }
            $mapping = (new HeaderMapper())->map($selectedHeader ?? []);
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw WorkbookException::invalid();
            }
            $part = $sheets[$selected];
            return new Workbook($selected, $mapping, $count, $hash, function () use ($path, $selected, $part, $mapping, $hash): Generator {
                if (hash_file('sha256', $path) !== $hash) {
                    throw WorkbookException::invalid();
                }
                $package = XlsxPackage::open($path, $this->limits);
                try {
                    foreach ($this->sheetRows($package, $path, $selected, $part) as $row) {
                        if ($row['number'] > 1 && !$this->isEmpty($row['cells'])) {
                            yield new WorkbookRow($row['number'], $row['cells'], $mapping);
                        }
                    }
                } finally {
                    $package->close();
                }
            });
        } catch (WorkbookException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw WorkbookException::invalid($exception);
        } finally {
            $package->close();
        }
    }

    /** @return Generator<int, array{number: int, cells: array<string, CellData>}> */
    private function sheetRows(XlsxPackage $package, string $path, string $sheetName, string $part): Generator
    {
        $book = null;
        $last = 0;
        try {
            foreach ($package->lexicalRows($part) as $row) {
                if ($book === null || $row['row'] > $last) {
                    $book?->disconnectWorksheets();
                    $reader = new Xlsx();
                    $reader->setLoadSheetsOnly($sheetName);
                    $reader->setReadDataOnly(false);
                    $reader->setIncludeCharts(false);
                    $first = intdiv($row['row'] - 1, $this->limits->chunkRows) * $this->limits->chunkRows + 1;
                    $last = $first + $this->limits->chunkRows - 1;
                    $reader->setReadFilter(new ChunkFilter($first, $last));
                    $book = $reader->load($path);
                }
                $sheet = $book->getSheetByName($sheetName);
                if ($sheet === null) {
                    throw WorkbookException::invalid();
                }
                $cells = [];
                foreach ($row['cells'] as $column => $lexical) {
                    $cell = $sheet->getCell($column . $row['row']);
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    if ($lexical['formula'] !== null) {
                        $cells[$column] = new CellData($lexical['formula'], 'f');
                    } elseif ($lexical['numeric'] !== null && $lexical['numeric'] !== '') {
                        $cells[$column] = new CellData($lexical['numeric'], 'n', $cell->getStyle()->getNumberFormat()->getFormatCode());
                    } else {
                        $cells[$column] = new CellData(is_bool($value) || $value === null ? $value : (string) $value, $cell->getDataType());
                    }
                }
                yield ['number' => $row['row'], 'cells' => $cells];
            }
        } catch (WorkbookException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw WorkbookException::invalid($exception);
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    /** @param array<string, CellData> $cells */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!$cell->isEmpty()) {
                return false;
            }
        }
        return true;
    }
}
