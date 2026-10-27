<?php
declare(strict_types=1);

namespace Chroma\Domain\Export;

use Chroma\Core\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    /** @param array<string,string> $columns @param iterable<array<string,mixed>> $rows */
    public static function xlsx(array $columns, iterable $rows): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Export');
        $c = 1;
        foreach ($columns as $heading) {
            $sheet->setCellValue([$c, 1], $heading);
            $sheet->getStyle([$c, 1])->getFont()->setBold(true);
            $c++;
        }
        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach (array_keys($columns) as $key) {
                $v = $row[$key] ?? '';
                if (is_bool($v)) {
                    $v = $v ? 'Yes' : 'No';
                }
                $sheet->setCellValueExplicit([$c, $r], (string) $v, is_int($v) || is_float($v) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $c++;
            }
            $r++;
        }
        for ($i = 1; $i <= count($columns); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $tmp = tempnam(sys_get_temp_dir(), 'chroma-xlsx');
        (new Xlsx($ss))->save($tmp);
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $out;
    }
}
