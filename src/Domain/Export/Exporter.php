<?php
declare(strict_types=1);

namespace Chroma\Domain\Export;

use Chroma\Core\Response;

/**
 * Turns a list of rows into a CSV or XLSX download.
 *
 *   $columns = ['project_number' => 'Project #', 'title' => 'Title', ...];
 *   return Exporter::respond($format, 'projects', $columns, $rows);
 *
 * Values are written as-is; format dates before passing them in.
 */
final class Exporter
{
    /**
     * @param array<string,string> $columns key => heading
     * @param iterable<array<string,mixed>> $rows
     */
    public static function respond(string $format, string $baseName, array $columns, iterable $rows): Response
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName) . '-' . gmdate('Ymd-Hi');
        return $format === 'xlsx'
            ? Response::download(self::xlsx($columns, $rows), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            : Response::download(self::csv($columns, $rows), $name . '.csv', 'text/csv; charset=utf-8');
    }

    /** @param array<string,string> $columns @param iterable<array<string,mixed>> $rows */
    public static function csv(array $columns, iterable $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open temp stream');
        }
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
        fputcsv($fh, array_values($columns), ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $v = $row[$key] ?? '';
                if (is_bool($v)) {
                    $v = $v ? 'Yes' : 'No';
                }
                $v = (string) $v;
                // Guard against formula injection when opened in Excel.
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) {
                    $v = "'" . $v;
                }
                $line[] = $v;
            }
            fputcsv($fh, $line, ',', '"', '\\', "\r\n");
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /**
     * A plain .xlsx written straight to a zip: one sheet, bold frozen heading
     * row, inline strings, sensible column widths. PhpSpreadsheet builds a
     * cell object per value and took ~5 s for the 5,000-row E log; this
     * streams the same file in a fraction of a second. PhpSpreadsheet is still
     * used to read the legacy spreadsheets.
     * @param array<string,string> $columns @param iterable<array<string,mixed>> $rows
     */
    public static function xlsx(array $columns, iterable $rows): string
    {
        $keys = array_keys($columns);
        $widths = [];
        foreach (array_values($columns) as $i => $heading) {
            $widths[$i] = mb_strlen((string) $heading);
        }
        $sheet = fopen('php://temp/maxmemory:8388608', 'w+');
        if ($sheet === false) {
            throw new \RuntimeException('Cannot open temp stream');
        }
        $r = 1;
        $line = '<row r="1">';
        foreach (array_values($columns) as $i => $heading) {
            $line .= '<c r="' . self::cellRef($i, 1) . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::xml((string) $heading) . '</t></is></c>';
        }
        fwrite($sheet, $line . '</row>');
        foreach ($rows as $row) {
            $r++;
            $line = '<row r="' . $r . '">';
            foreach ($keys as $i => $key) {
                $v = $row[$key] ?? '';
                if (is_bool($v)) {
                    $v = $v ? 'Yes' : 'No';
                }
                $ref = self::cellRef($i, $r);
                if (is_int($v) || is_float($v)) {
                    $line .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                    $len = strlen((string) $v);
                } else {
                    $v = (string) $v;
                    if ($v === '') {
                        continue;
                    }
                    $line .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml($v) . '</t></is></c>';
                    $len = mb_strlen($v);
                }
                if ($r <= 201 && $len > $widths[$i]) {
                    // Column widths from the heading and the first 200 rows, capped so one long note does not stretch a column.
                    $widths[$i] = min(60, $len);
                }
            }
            fwrite($sheet, $line . '</row>');
        }
        $last = self::cellRef(count($keys) - 1, $r);
        $cols = '<cols>';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($w + 2) . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $last . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>' . $cols . '<sheetData>';
        $tail = '</sheetData></worksheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'chroma-xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create the Excel file');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        rewind($sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $head . (string) stream_get_contents($sheet) . $tail);
        fclose($sheet);
        $zip->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    /** A1-style reference for a 0-based column and 1-based row. */
    private static function cellRef(int $col, int $row): string
    {
        $letters = '';
        $n = $col + 1;
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $letters = chr(65 + $m) . $letters;
            $n = intdiv($n - 1, 26);
        }
        return $letters . $row;
    }

    /** XML text with the characters spreadsheets cannot hold (control bytes) removed. */
    private static function xml(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
