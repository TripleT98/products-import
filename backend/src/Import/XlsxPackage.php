<?php

declare(strict_types=1);

namespace App\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;
use XMLReader;
use ZipArchive;

/** Read-only OOXML preflight and exact lexical numeric values (no numeric float conversion). */
final class XlsxPackage
{
    private function __construct(private ZipArchive $zip, private WorkbookLimits $limits)
    {
    }

    public static function open(string $path, WorkbookLimits $limits): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw WorkbookException::invalid();
        }
        $size = filesize($path);
        if ($size === false || $size > $limits->maxFileBytes) {
            throw WorkbookException::limit();
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw WorkbookException::invalid();
        }
        $package = new self($zip, $limits);
        try {
            $package->validate();
            return $package;
        } catch (Throwable $exception) {
            $zip->close();
            throw $exception;
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function validate(): void
    {
        if ($this->zip->numFiles > $this->limits->maxZipEntries) {
            throw WorkbookException::limit();
        }
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; ++$i) {
            $entry = $this->zip->statIndex($i);
            if ($entry === false) {
                throw WorkbookException::invalid();
            }
            $name = $entry['name'];
            $decoded = rawurldecode($name);
            if (isset($seen[$name]) || str_starts_with($decoded, '/') || str_contains($decoded, '\\') || str_contains($decoded, ':')
                || str_contains($decoded, "\0") || in_array('..', explode('/', $decoded), true) || in_array('.', explode('/', $decoded), true)
                || $entry['encryption_method'] !== 0) {
                throw WorkbookException::invalid();
            }
            $seen[$name] = true;
            $total += $entry['size'];
            if ($total > $this->limits->maxUncompressedBytes || $entry['size'] > max(1, $entry['comp_size']) * $this->limits->maxCompressionRatio) {
                throw WorkbookException::limit();
            }
            if (str_ends_with(strtolower($name), 'vbaproject.bin') || str_starts_with(strtolower($name), 'xl/externallinks/')) {
                throw WorkbookException::invalid();
            }
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                foreach ($this->xml($name) as $node) {
                    if ($node->nodeType !== XMLReader::ELEMENT) {
                        continue;
                    }
                    if ($node->localName === 'Relationship' && !str_ends_with($node->getAttribute('Type') ?? '', '/hyperlink')) {
                        $target = rawurldecode($node->getAttribute('Target') ?? '');
                        if (str_contains($target, ':') || str_contains($target, '\\')) {
                            throw WorkbookException::invalid();
                        }
                    }
                    if ((strtolower($node->getAttribute('TargetMode') ?? '') === 'external' && !str_ends_with($node->getAttribute('Type') ?? '', '/hyperlink')) || str_contains(strtolower($node->getAttribute('ContentType') ?? ''), 'macroenabled')) {
                        throw WorkbookException::invalid();
                    }
                }
            }
        }
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
            if (!isset($seen[$required])) {
                throw WorkbookException::invalid();
            }
        }
    }

    /** @return Generator<int, XMLReader> */
    private function xml(string $part): Generator
    {
        $stream = $this->zip->getStream($part);
        if ($stream === false) {
            throw WorkbookException::invalid();
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        try {
            $reader = XMLReader::fromStream($stream, null, LIBXML_NONET | LIBXML_COMPACT);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                    throw WorkbookException::invalid();
                }
                yield $reader;
            }
            if (libxml_get_errors() !== []) {
                throw WorkbookException::invalid();
            }
        } finally {
            $reader?->close();
            if (is_resource($stream)) {
                fclose($stream);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<array-key, string> sheet title => ZIP part */
    public function sheets(): array
    {
        $targets = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels') as $node) {
            if ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'Relationship' && str_ends_with($node->getAttribute('Type') ?? '', '/worksheet')) {
                $target = $node->getAttribute('Target') ?? '';
                if ($target === '' || str_contains($target, ':') || str_contains($target, '\\')) {
                    throw WorkbookException::invalid();
                }
                $parts = [];
                foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target) as $part) {
                    if ($part === '..') {
                        if ($parts === []) {
                            throw WorkbookException::invalid();
                        }
                        array_pop($parts);
                    } elseif ($part !== '' && $part !== '.') {
                        $parts[] = $part;
                    }
                }
                $targets[$node->getAttribute('Id') ?? ''] = implode('/', $parts);
            }
        }
        $sheets = [];
        foreach ($this->xml('xl/workbook.xml') as $node) {
            if ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'sheet') {
                $name = $node->getAttribute('name') ?? '';
                $id = $node->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                    ?? $node->getAttributeNs('id', 'http://purl.oclc.org/ooxml/officeDocument/relationships');
                if ($name === '' || isset($sheets[$name]) || !isset($targets[$id ?? '']) || $this->zip->locateName($targets[$id]) === false) {
                    throw WorkbookException::invalid();
                }
                $sheets[$name] = $targets[$id];
            }
        }
        if ($sheets === []) {
            throw WorkbookException::invalid();
        }
        return $sheets;
    }

    /** @return Generator<int, array{row: int, cells: array<string, array{numeric: ?string, formula: ?string}>}> */
    public function lexicalRows(string $part): Generator
    {
        $row = $previousRow = $columnIndex = 0;
        $cells = [];
        $column = null;
        $type = '';
        $numeric = $formula = null;
        foreach ($this->xml($part) as $node) {
            if ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'row') {
                $number = $node->getAttribute('r') ?? (string) ($previousRow + 1);
                if (!ctype_digit($number) || strlen($number) > 7 || (int) $number <= $previousRow) {
                    throw WorkbookException::invalid();
                }
                $row = (int) $number;
                if ($row > $this->limits->maxRowNumber) {
                    throw WorkbookException::limit();
                }
                $previousRow = $row;
                $cells = [];
                $columnIndex = 0;
                if ($node->isEmptyElement) {
                    yield ['row' => $row, 'cells' => []];
                }
            } elseif ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'c') {
                if ($row < 1) {
                    throw WorkbookException::invalid();
                }
                $coordinate = $node->getAttribute('r') ?? Coordinate::stringFromColumnIndex($columnIndex + 1) . $row;
                if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D', $coordinate, $matches) || (int) $matches[2] !== $row) {
                    throw WorkbookException::invalid();
                }
                $column = $matches[1];
                $columnIndex = Coordinate::columnIndexFromString($column);
                if ($columnIndex > 16384 || isset($cells[$column])) {
                    throw WorkbookException::invalid();
                }
                $type = $node->getAttribute('t') ?? 'n';
                $numeric = $formula = null;
                if ($node->isEmptyElement) {
                    $cells[$column] = ['numeric' => null, 'formula' => null];
                    $column = null;
                }
            } elseif ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'f' && $column !== null) {
                $formula = '=' . $node->readString();
            } elseif ($node->nodeType === XMLReader::ELEMENT && $node->localName === 'v' && $column !== null && $type === 'n') {
                $numeric = $node->readString();
            } elseif ($node->nodeType === XMLReader::END_ELEMENT && $node->localName === 'c' && $column !== null) {
                $cells[$column] = ['numeric' => $numeric, 'formula' => $formula];
                $column = null;
            } elseif ($node->nodeType === XMLReader::END_ELEMENT && $node->localName === 'row') {
                yield ['row' => $row, 'cells' => $cells];
            }
        }
    }
}
