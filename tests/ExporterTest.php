<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Domain\Export\Exporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/** The CSV and Excel writers behind every "Export" button. */
final class ExporterTest extends TestCase
{
    private const COLUMNS = ['code' => 'Sell number', 'description' => 'Description', 'count' => 'Codes', 'note' => 'Note'];

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['code' => 'Y1715UV', 'description' => 'Yellow & "UV" <varnish>', 'count' => 12, 'note' => "line one\nline two"],
            ['code' => 'R2324', 'description' => '=SUM(A1)', 'count' => 0.5, 'note' => ''],
            ['code' => 'Ünïcode', 'description' => 'Pantone 163 C', 'count' => true, 'note' => 'ends with space '],
        ];
    }

    public function testXlsxOpensInASpreadsheetReaderWithHeadingsValuesAndTypes(): void
    {
        $bytes = Exporter::xlsx(self::COLUMNS, $this->rows());
        $tmp = tempnam(sys_get_temp_dir(), 'chroma-test-xlsx');
        file_put_contents($tmp, $bytes);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $this->assertSame('Export', $sheet->getTitle());
        $this->assertSame(['Sell number', 'Description', 'Codes', 'Note'], $sheet->rangeToArray('A1:D1')[0]);
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold(), 'heading row is bold');
        $this->assertSame('A2', $sheet->getFreezePane(), 'heading row stays visible');
        $text = fn(string $ref) => (string) $sheet->getCell($ref)->getFormattedValue();
        $this->assertSame(['Y1715UV', 'Yellow & "UV" <varnish>', "line one\nline two"], [$text('A2'), $text('B2'), $text('D2')]);
        $this->assertSame(12, $sheet->getCell('C2')->getValue(), 'numbers are real numbers, not text');
        $this->assertSame('=SUM(A1)', $text('B3'), 'text that looks like a formula stays text');
        $this->assertSame(0.5, $sheet->getCell('C3')->getValue());
        $this->assertSame('Ünïcode', $text('A4'));
        $this->assertSame('Yes', $text('C4'));
        $this->assertSame('ends with space ', $text('D4'));
        $this->assertNull($sheet->getCell('D3')->getValue(), 'an empty value is an empty cell');
        $this->assertGreaterThan(20, $sheet->getColumnDimension('B')->getWidth(), 'columns are sized to their content');
    }

    public function testXlsxOfAFewThousandRowsIsQuick(): void
    {
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = ['code' => 'E' . (9102 + $i), 'description' => 'Pantone ' . $i . ' C test description that is fairly long', 'count' => $i, 'note' => 'Assigned in Chroma'];
        }
        $start = microtime(true);
        $bytes = Exporter::xlsx(self::COLUMNS, $rows);
        $this->assertLessThan(2.0, microtime(true) - $start, 'a 5,000-row export must stay well under the 2 s target');
        $this->assertStringStartsWith("PK", $bytes);
    }

    public function testCsvGuardsAgainstFormulaInjection(): void
    {
        $csv = Exporter::csv(self::COLUMNS, $this->rows());
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Sell number\",Description,Codes,Note\r\n", $csv);
        $this->assertStringContainsString("R2324,'=SUM(A1),0.5,", $csv);
        $this->assertStringContainsString('Ünïcode,"Pantone 163 C",Yes,', $csv);
    }
}
