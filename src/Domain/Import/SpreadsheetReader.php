<?php
declare(strict_types=1);

namespace Chroma\Domain\Import;

use Chroma\Core\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads one legacy spreadsheet (.xls or .xlsx) and identifies it by its
 * header row, never by its file name. The header may sit on any of the
 * first rows (title rows above it are common); matching is case- and
 * space-insensitive.
 *
 * Recognised layouts:
 *   crossref  Internal Code, Sell Number, Date, Original Customer, Description,
 *             Resale, Vendor Name, Vendor Code, Handschy Number
 *   codelog   Code, Description, Internal Code, Comments   (the Security
 *             Product Code Log and the UV Product Code Log; told apart by
 *             the prefix of the codes inside)
 *
 * Every row comes back with the same field names whatever the layout, so
 * the importer treats them alike:
 *   row, internal_code, sell_number, date (YYYY-MM-DD or null), date_raw,
 *   customer, description, resale, vendor_name, vendor_code, handschy, comments
 */
final class SpreadsheetReader
{
    public const KIND_CROSSREF = 'crossref';
    public const KIND_XLOG = 'xlog';
    public const KIND_UVLOG = 'uvlog';
    public const KIND_CODELOG = 'codelog';

    /** How many leading rows are searched for the header. */
    private const HEADER_SEARCH_ROWS = 40;

    /** Normalized header text => field name, per layout. */
    private const CROSSREF_COLUMNS = [
        'internalcode' => 'internal_code', 'internalcodes' => 'internal_code', 'enumber' => 'internal_code', 'eno' => 'internal_code',
        'sellnumber' => 'sell_number', 'sellno' => 'sell_number', 'sellnumbers' => 'sell_number',
        'date' => 'date', 'dateassigned' => 'date',
        'originalcustomer' => 'customer', 'customer' => 'customer',
        'description' => 'description', 'desc' => 'description',
        'resale' => 'resale',
        'vendorname' => 'vendor_name', 'vendor' => 'vendor_name',
        'vendorcode' => 'vendor_code',
        'handschynumber' => 'handschy', 'handschy' => 'handschy', 'handschyno' => 'handschy',
        'comments' => 'comments', 'notes' => 'comments',
    ];

    private const CODELOG_COLUMNS = [
        'code' => 'sell_number', 'codes' => 'sell_number', 'productcode' => 'sell_number', 'sellnumber' => 'sell_number',
        'description' => 'description', 'desc' => 'description',
        'internalcode' => 'internal_code', 'internalcodes' => 'internal_code', 'enumber' => 'internal_code',
        'comments' => 'comments', 'comment' => 'comments', 'notes' => 'comments',
        'date' => 'date', 'dateassigned' => 'date',
        'customer' => 'customer', 'originalcustomer' => 'customer',
    ];

    public static function labelFor(string $kind): string
    {
        return match ($kind) {
            self::KIND_CROSSREF => 'Code Cross Reference',
            self::KIND_XLOG => 'Security Product Code Log',
            self::KIND_UVLOG => 'UV Product Code Log',
            self::KIND_CODELOG => 'Product code log',
            default => 'Unrecognised spreadsheet',
        };
    }

    /**
     * Read a file. Throws ValidationException (plain language) when the file
     * cannot be opened or no known header row is found.
     *
     * @return array{kind:string,label:string,sheet:string,header_row:int,columns:array<string,int>,rows:list<array<string,mixed>>,row_count:int}
     */
    public function read(string $path, ?string $originalName = null): array
    {
        $name = $originalName ?? basename($path);
        if (!is_file($path) || !is_readable($path)) {
            throw ValidationException::general("The file \"$name\" could not be opened. Upload it again.");
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx', 'xlsm'], true)) {
            throw ValidationException::general("\"$name\" is not an Excel file. Chroma reads .xls and .xlsx files only; save the sheet as one of those and try again.");
        }
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            throw ValidationException::general("\"$name\" could not be read as a spreadsheet (" . $e->getMessage() . "). Open it in Excel, save it again as .xlsx, and retry.");
        }

        try {
            foreach ($book->getAllSheets() as $sheet) {
                $grid = $sheet->toArray(null, false, false, false);
                $found = $this->findHeader($grid);
                if ($found === null) {
                    continue;
                }
                [$headerIndex, $layout, $columns] = $found;
                $rows = $this->extractRows($grid, $headerIndex, $columns);
                $kind = $layout === self::KIND_CROSSREF ? self::KIND_CROSSREF : $this->codelogKind($rows);
                return [
                    'kind' => $kind,
                    'label' => self::labelFor($kind),
                    'sheet' => $sheet->getTitle(),
                    'header_row' => $headerIndex + 1,
                    'columns' => $columns,
                    'rows' => $rows,
                    'row_count' => count($rows),
                ];
            }
        } finally {
            $book->disconnectWorksheets();
        }
        throw ValidationException::general(
            "\"$name\" does not look like one of the three legacy sheets. Chroma looks for a header row with either "
            . '"Internal Code, Sell Number, Date, Original Customer, Description…" (the cross-reference) or '
            . '"Code, Description, Internal Code, Comments" (the Security and UV code logs). Check the file and try again.'
        );
    }

    /** Lowercase letters and digits only: "Internal Code " => "internalcode". */
    public static function normalizeHeader(mixed $cell): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $cell))) ?? '';
    }

    /**
     * @param list<list<mixed>> $grid
     * @return array{0:int,1:string,2:array<string,int>}|null [header row index, layout, field => column index]
     */
    private function findHeader(array $grid): ?array
    {
        $limit = min(count($grid), self::HEADER_SEARCH_ROWS);
        for ($i = 0; $i < $limit; $i++) {
            $cells = array_map([self::class, 'normalizeHeader'], $grid[$i] ?? []);
            $present = array_filter($cells, fn($c) => $c !== '');
            if (count($present) < 3) {
                continue;
            }
            $set = array_flip($present);
            // Cross-reference: must have Internal Code + Sell Number + Description.
            if (isset($set['internalcode']) && (isset($set['sellnumber']) || isset($set['sellno'])) && (isset($set['description']) || isset($set['desc']))) {
                return [$i, self::KIND_CROSSREF, $this->mapColumns($cells, self::CROSSREF_COLUMNS)];
            }
            // Code log: Code + Description + Internal Code (no Sell Number column).
            if ((isset($set['code']) || isset($set['productcode'])) && isset($set['internalcode']) && (isset($set['description']) || isset($set['desc']))) {
                return [$i, self::KIND_CODELOG, $this->mapColumns($cells, self::CODELOG_COLUMNS)];
            }
        }
        return null;
    }

    /** @param list<string> $cells @param array<string,string> $map @return array<string,int> */
    private function mapColumns(array $cells, array $map): array
    {
        $columns = [];
        foreach ($cells as $idx => $norm) {
            if ($norm !== '' && isset($map[$norm]) && !isset($columns[$map[$norm]])) {
                $columns[$map[$norm]] = $idx;
            }
        }
        return $columns;
    }

    /**
     * @param list<list<mixed>> $grid
     * @param array<string,int> $columns
     * @return list<array<string,mixed>>
     */
    private function extractRows(array $grid, int $headerIndex, array $columns): array
    {
        $rows = [];
        $n = count($grid);
        for ($i = $headerIndex + 1; $i < $n; $i++) {
            $line = $grid[$i];
            $row = ['row' => $i + 1];
            $blank = true;
            foreach (['internal_code', 'sell_number', 'date', 'customer', 'description', 'resale', 'vendor_name', 'vendor_code', 'handschy', 'comments'] as $field) {
                $raw = isset($columns[$field]) ? ($line[$columns[$field]] ?? null) : null;
                if ($field === 'date') {
                    $row['date_raw'] = $raw === null ? '' : trim((string) $raw);
                    $row['date'] = self::parseDate($raw);
                    if ($row['date_raw'] !== '') {
                        $blank = false;
                    }
                    continue;
                }
                $text = self::cleanText($raw);
                $row[$field] = $text;
                if ($text !== '') {
                    $blank = false;
                }
            }
            if ($blank) {
                continue; // skip empty lines entirely
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Trim, collapse internal whitespace, turn floats like 1234.0 into "1234". */
    public static function cleanText(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (is_float($raw)) {
            $raw = floor($raw) === $raw ? (string) (int) $raw : (string) $raw;
        }
        $s = trim((string) $raw);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }

    /**
     * Excel serial numbers, ISO dates and common US text dates => YYYY-MM-DD.
     * Anything unreadable => null (the raw text is kept beside it).
     */
    /** Excel serials for 1950-01-01 and 2100-12-31: the range a legacy date can plausibly fall in. */
    private const MIN_SERIAL = 18264;
    private const MAX_SERIAL = 73415;

    public static function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw) || (is_string($raw) && preg_match('/^\d{4,6}(\.\d+)?$/', trim($raw)))) {
            // An Excel serial. Anything before 1950 is not a date the lab ever used: a bare
            // year such as "2019" would otherwise come out as 1905-07-11, so it is unreadable.
            $serial = (float) $raw;
            if ($serial < self::MIN_SERIAL || $serial > self::MAX_SERIAL) {
                return null;
            }
            try {
                return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'n/j/Y', 'n/j/y', 'm/d/Y', 'm-d-Y', 'n-j-Y', 'n-j-y', 'M j, Y', 'j M Y', 'd-M-y', 'd-M-Y', 'F j, Y', 'Y/m/d', 'n/j/Y G:i', 'n/j/Y g:i A'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            if ($d !== false) {
                $y = (int) $d->format('Y');
                if ($y >= 1950 && $y <= 2100) {
                    return $d->format('Y-m-d');
                }
            }
        }
        $t = strtotime($s);
        if ($t !== false) {
            $y = (int) date('Y', $t);
            if ($y >= 1950 && $y <= 2100) {
                return date('Y-m-d', $t);
            }
        }
        return null;
    }

    /** A code log is the X log or the UV log depending on the codes inside it. */
    private function codelogKind(array $rows): string
    {
        $x = 0;
        $uv = 0;
        foreach ($rows as $r) {
            $code = strtoupper((string) ($r['sell_number'] ?? ''));
            if (preg_match('/^X\s*-?\s*\d/', $code)) {
                $x++;
            } elseif (preg_match('/^UV\s*-?\s*\d/', $code)) {
                $uv++;
            }
            if ($x + $uv > 50) {
                break;
            }
        }
        if ($x === 0 && $uv === 0) {
            return self::KIND_CODELOG;
        }
        return $x >= $uv ? self::KIND_XLOG : self::KIND_UVLOG;
    }
}
