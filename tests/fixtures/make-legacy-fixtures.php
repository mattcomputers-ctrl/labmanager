<?php
/**
 * Builds realistic stand-ins for the three legacy spreadsheets (the real
 * ones are not in the repo). Every edge case named in SPEC.md §2 is in
 * here: hyphenated and lowercase sell numbers, UVV rows, duplicate sell
 * numbers (a typo and a genuine relabel), rows with nothing on them,
 * pre-filled empty E# rows, resale rows with vendor fields, Handschy
 * numbers, X codes with and without E#, comments worth keeping, legacy
 * non-E internal codes, junk rows, unknown prefixes and suffixes, E# with
 * versions, Excel dates as serials and as text, trailing spaces, blank
 * rows and a header row that is not on row 1.
 *
 *   php tests/fixtures/make-legacy-fixtures.php            writes tests/fixtures/legacy/
 *   php tests/fixtures/make-legacy-fixtures.php DIR        writes to DIR
 *   php tests/fixtures/make-legacy-fixtures.php DIR --large=8000   adds a big cross-reference for timing
 *
 * The test suite calls make_legacy_fixtures() itself when the files are missing.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!function_exists('make_legacy_fixtures')) {
    /**
     * @return array{crossref:string,xlog:string,uvlog:string,catchup:string,large?:string}
     */
    function make_legacy_fixtures(string $dir, int $largeRows = 0): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }
        $serial = fn(string $ymd) => ExcelDate::PHPToExcel(new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC')));

        // ---- 1. Code Cross Reference (.xls, header on row 3) ----------------
        $crossHeader = ['Internal Code', 'Sell Number', 'Date', 'Original Customer', 'Description', 'Resale', 'Vendor Name', 'Vendor Code', 'Handschy Number'];
        $crossRows = [
            ['E1010', 'Y-1000', $serial('1998-05-04'), 'Acme Labels', 'Process Yellow', '', '', '', ''],
            ['E1011', 'y-1001uv', '3/14/2001', 'Acme Labels', 'Process Yellow UV', '', '', '', ''],
            ['E1012', 'BK-1048', $serial('1998-07-21'), 'Baxter Packaging', 'Dense Black', '', '', '', ''],
            [null, null, null, null, null, null, null, null, null], // blank row
            ['E6374-10', 'B2970UV', '2019-03-14', 'Cartwright Print', 'Reflex Blue UV v10', '', '', '', ''],
            ['E6374-12', 'B2970', $serial('2020-01-10'), 'Cartwright Print', 'Reflex Blue v12', '', '', '', ''],
            ['E7001', 'MS1400', $serial('2015-06-02'), 'Dunn Flexo', 'Overprint varnish (resale)', 'Yes', 'Flint Group', 'FG-OPV-12', 'H-4421'],
            ['E7100', 'Y1500', $serial('2016-02-11'), 'Acme Labels', 'Warm Yellow', '', '', '', ''],
            ['E7150', 'Y1500', $serial('2016-04-19'), 'Evergreen Foods', 'Lemon Yellow', '', '', '', ''],          // duplicate (typo)
            ['E7200', 'R2100', $serial('2017-09-01'), 'Fenwick Labels', 'Rhodamine Red', '', '', '', ''],
            ['E7200', 'R2156', $serial('2018-03-05'), 'Fenwick Labels', 'Rhodamine Red relabel', '', '', '', ''],  // genuine relabel: same E#, second sell number
            ['E7300', '', $serial('2018-05-05'), 'Gable Press', 'Formula pending', '', '', '', ''],              // E# only, sell number pending
            ['', 'T2449', $serial('2026-10-20'), 'Hale Graphics', 'Blister Pantone 663 Purple', '', '', '', ''], // sell number with no E#
            ['E8000', 'UVV185', $serial('2021-01-15'), 'Ingram Inks', 'Inherited UVV flexo white', '', '', '', ''],
            ['E8001', 'UVV-184', $serial('2021-01-15'), 'Ingram Inks', 'Inherited UVV flexo varnish', '', '', '', ''],
            ['E8100', '38-5000', $serial('2021-02-01'), 'Jensen Labels', 'Not a code at all', '', '', '', ''],      // junk
            ['E8101', 'PURCHASED ONLY', $serial('2021-02-01'), 'Jensen Labels', 'Purchased item', '', '', '', ''],  // junk
            ['E8200', 'ZQ100', $serial('2021-03-01'), 'Keel Foods', 'Unknown prefix one', '', '', '', ''],
            ['E8201', 'ZQ101', $serial('2021-03-02'), 'Keel Foods', 'Unknown prefix two', '', '', '', ''],
            ['E8300', 'Y1600XY', $serial('2021-04-01'), 'Lund Print', 'Unknown suffix row', '', '', '', ''],
            ['3UC4779', 'UV25510', $serial('2010-08-08'), 'Mercer Labels', 'Legacy UV white', '', '', '', ''],      // legacy non-E internal code
            [' E8400 ', ' G2101 ', $serial('2022-05-05'), ' Noble Foods ', ' Trailing spaces green ', '', '', '', ''],
            ['', '', null, '', 'Orphan description without any code', '', '', '', ''],                               // description, no code
            ['E8500', 'A0012', $serial('2001-01-01'), 'Prior Graphics', 'Retired prefix A row', '', '', '', ''],
            ['E9000', 'X104043', $serial('2026-10-20'), 'Quill Security', 'Security ink block 104', '', '', '', ''],
            ['E9101', 'T2448', $serial('2026-10-26'), 'Ridge Labels', 'Last used E number', '', '', '', ''],
            ['E9050', 'W1098', 'not a date', 'Sable Print', 'Bad date row', '', '', '', ''],
            ['E8600', 'P1555', $serial('2023-01-09'), 'Tarn Packaging', '', '', '', '', ''],                          // no description
            ['e8700', 'or-1453', $serial('2023-02-09'), 'Ulster Print', 'Lowercase E and prefix', '', '', '', ''],
            ['E8800', 'BN1439', null, 'Vance Labels', 'Brown no date', '', '', '', ''],
            ['E8900', 'AQ5254', $serial('2024-04-04'), 'Wyeth Coatings', 'Aqueous coating', '', '', '', ''],
            ['E8901', 'WBC1030', $serial('2014-04-04'), 'Wyeth Coatings', 'Legacy aqueous', '', '', '', ''],
            ['E8902', 'UVXL428', $serial('2024-05-05'), 'Xeno Flexo', 'Inherited UVXL', '', '', '', ''],
            ['E8903', 'UVNG72', $serial('2024-05-06'), 'Xeno Flexo', 'Inherited UVNG', '', '', '', ''],
            ['E8904', 'UV25672', $serial('2024-06-06'), 'Yost Print', 'UV legacy series last', '', '', '', ''],
            ['E8905', 'HBK0100', $serial('2004-06-06'), 'Zed Packaging', 'Retired HBK with leading zero', '', '', '', ''],
        ];
        for ($n = 9102; $n <= 9400; $n++) { // pre-filled empty E# rows (the real sheet runs to E11009)
            $crossRows[] = ['E' . $n, '', null, '', '', '', '', '', ''];
        }
        $crossref = $dir . '/code-cross-reference.xls';
        write_sheet($crossref, 'Cross Reference', [['Precision Ink — Code Cross Reference'], ['Internal codes and sell numbers, maintained by the lab'], $crossHeader], $crossRows, 'xls');

        // ---- 2. Security Product Code Log (.xlsx, header on row 1) ----------
        $logHeader = ['Code', 'Description', 'Internal Code', 'Comments'];
        $xRows = [
            ['X102001', 'Early security black', 'E5001', ''],
            ['X102002', 'Early security blue', 'E5002', 'Same E#, new formula version'],
            ['X103500', 'Security red', 'E5003-2', 'Also coded as R2156'],
            ['X104040', 'Security UV invisible', 'E8950', ''],
            ['X104041', 'Security green', '', ''],                 // no E# yet
            ['X104042', 'Security magenta', '', 'E# to follow'],   // no E# yet
            ['X104043', 'Security ink block 104', 'E9000', 'Same as cross-reference row'],
        ];
        for ($n = 104044; $n <= 104500; $n++) { // pre-filled blank codes to X104500
            $xRows[] = ['X' . $n, '', '', ''];
        }
        $xlog = $dir . '/security-product-code-log.xlsx';
        write_sheet($xlog, 'X Codes', [$logHeader], $xRows, 'xlsx');

        // ---- 3. UV Product Code Log (.xlsx, header on row 2) -------------
        $uvUsed = [
            25500 => ['UV flexo white', '2UY0480', 'legacy formula book'],
            25501 => ['UV flexo varnish', '3UC4779', ''],
            25502 => ['UV process cyan', 'E8960', ''],
            25510 => ['Legacy UV white', '3UC4779', 'also in cross-reference'],
            25672 => ['UV legacy series last', 'E8904', ''],
        ];
        $uvRows = [];
        for ($n = 25500; $n <= 25900; $n++) {
            $uvRows[] = isset($uvUsed[$n]) ? array_merge(['UV' . $n], $uvUsed[$n]) : ['UV' . $n, '', '', ''];
        }
        $uvlog = $dir . '/uv-product-code-log.xlsx';
        write_sheet($uvlog, 'UV Codes', [['UV Product Code Log'], $logHeader], $uvRows, 'xlsx');

        // ---- 4. A catch-up cross-reference: same rows plus a few new ones ----
        $catchRows = $crossRows;
        $catchRows[] = ['E9150', 'Y1715', $serial('2026-10-27'), 'Acme Labels', 'New yellow since cutover', '', '', '', ''];
        $catchRows[] = ['E9151', 'B2971UV', $serial('2026-10-27'), 'Acme Labels', 'New blue since cutover', '', '', '', ''];
        $catchRows[] = ['E7300', 'R2200', $serial('2026-10-27'), 'Gable Press', 'Formula pending', '', '', '', ''];   // existing E# gains a sell number
        $catchRows[] = ['E8800', 'BN1439', $serial('2024-02-02'), 'Vance Labels', 'Brown no date', '', '', '', ''];   // fills the missing date only
        $catchRows[] = ['E9302', 'R2100', $serial('2026-10-27'), 'Fenwick Labels', 'Rhodamine Red, re-entered under a new E#', '', '', '', '']; // R2100 already belongs to E7200: a conflict to report, not swallow
        $catchRows[] = ['E9303', 'Y1720', '2019', 'Acme Labels', 'Year typed as text in the date column', '', '', '', ''];       // "2019" is not a date (and not an Excel serial)
        $catchup = $dir . '/code-cross-reference-catchup.xlsx';
        write_sheet($catchup, 'Cross Reference', [$crossHeader], $catchRows, 'xlsx');

        $out = ['crossref' => $crossref, 'xlog' => $xlog, 'uvlog' => $uvlog, 'catchup' => $catchup];

        // ---- 5. Optional large cross-reference for timing -----------------
        if ($largeRows > 0) {
            $prefixes = ['Y', 'R', 'B', 'BK', 'G', 'P', 'BN', 'OR', 'W', 'MS', 'T'];
            $customers = ['Acme Labels', 'Baxter Packaging', 'Cartwright Print', 'Dunn Flexo', 'Evergreen Foods', 'Fenwick Labels', 'Gable Press', 'Hale Graphics'];
            $rows = [];
            $counter = array_fill_keys($prefixes, 1000);
            $e = 1010;
            for ($i = 0; $i < $largeRows; $i++) {
                $p = $prefixes[$i % count($prefixes)];
                $num = $counter[$p]++;
                $sfx = $i % 5 === 0 ? 'UV' : '';
                $rows[] = ['E' . $e++, $p . $num . $sfx, $serial(date('Y-m-d', 883612800 + (int) ($i * 1.2) * 86400)), $customers[$i % 8], 'Color match ' . $i . ' Pantone ' . (100 + $i % 800), $i % 50 === 0 ? 'Yes' : '', $i % 50 === 0 ? 'Vendor Co' : '', '', ''];
            }
            for ($n = $e; $n <= $e + 1900; $n++) {
                $rows[] = ['E' . $n, '', null, '', '', '', '', '', ''];
            }
            $large = $dir . '/large-cross-reference.xls';
            write_sheet($large, 'Cross Reference', [$crossHeader], $rows, 'xls');
            $out['large'] = $large;
        }
        return $out;
    }

    /** @param list<list<mixed>> $head @param list<list<mixed>> $rows */
    function write_sheet(string $path, string $title, array $head, array $rows, string $format): void
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($title);
        $r = 1;
        foreach (array_merge($head, $rows) as $line) {
            $c = 1;
            foreach ($line as $v) {
                if ($v !== null && $v !== '') {
                    if (is_string($v)) {
                        $sheet->setCellValueExplicit([$c, $r], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue([$c, $r], $v);
                    }
                }
                $c++;
            }
            $r++;
        }
        $writer = $format === 'xls' ? new Xls($ss) : new Xlsx($ss);
        $writer->save($path);
        $ss->disconnectWorksheets();
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $dir = $argv[1] ?? __DIR__ . '/legacy';
    $large = 0;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--large=')) {
            $large = (int) substr($a, 8);
        }
    }
    foreach (make_legacy_fixtures($dir, $large) as $k => $p) {
        echo str_pad($k, 10) . $p . ' (' . number_format((int) filesize($p)) . " bytes)\n";
    }
}
