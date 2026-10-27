<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Domain\Codes\CodeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeFormatTest extends TestCase
{
    /** @return list<array{string,?string}> */
    public static function sellCases(): array
    {
        return [
            ['Y1714UV', 'Y1714UV'],
            ['y1714uv', 'Y1714UV'],
            ['Y-1000', 'Y1000'],
            ['Y 1000', 'Y1000'],
            ['y - 1000 uv', 'Y1000UV'],
            ['BK-1048', 'BK1048'],
            ['b2978uv', 'B2978UV'],
            ['X104043', 'X104043'],
            ['UV25672', 'UV25672'],
            ['UVXL428', 'UVXL428'],
            ['UVNG072', 'UVNG72'],
            ['T2446UV', 'T2446UV'],
            ['38-5000', null],
            ['PURCHASED ONLY', null],
            ['', null],
            ['Y', null],
            ['1234', null],
            ['Y0000', null],
        ];
    }

    #[DataProvider('sellCases')]
    public function testNormalizeSell(string $in, ?string $expected): void
    {
        $this->assertSame($expected, CodeFormat::normalizeSell($in));
    }

    public function testParseSellParts(): void
    {
        $p = CodeFormat::parseSell('t-2446uv');
        $this->assertSame(['prefix' => 'T', 'number' => 2446, 'suffix' => 'UV', 'normalized' => 'T2446UV'], $p);
        $p = CodeFormat::parseSell('X104043');
        $this->assertSame('X', $p['prefix']);
        $this->assertSame(104043, $p['number']);
        $this->assertSame('', $p['suffix']);
    }

    public function testSellDisplayPadsToMinDigits(): void
    {
        $this->assertSame('Y1715', CodeFormat::sell('Y', 1715, 4));
        $this->assertSame('Y0099UV', CodeFormat::sell('Y', 99, 4, 'UV'));
        $this->assertSame('X104044', CodeFormat::sell('X', 104044, 6));
        $this->assertSame('UVNG72', CodeFormat::sell('UVNG', 72, 2));
        $this->assertSame('UVNG172', CodeFormat::sell('UVNG', 172, 2));
    }

    public function testInternal(): void
    {
        $this->assertSame(['number' => 6374, 'version' => '10'], CodeFormat::parseInternal('E6374-10'));
        $this->assertSame(['number' => 9101, 'version' => ''], CodeFormat::parseInternal('e 9101'));
        $this->assertSame(['number' => 9101, 'version' => ''], CodeFormat::parseInternal('9101'));
        $this->assertNull(CodeFormat::parseInternal('2UY0480'));
        $this->assertNull(CodeFormat::parseInternal('E0'));
        $this->assertSame('E6374-10', CodeFormat::internal(6374, '10'));
        $this->assertSame('E9102', CodeFormat::internal(9102, ''));
        $this->assertTrue(CodeFormat::looksInternal('E7564-23'));
        $this->assertFalse(CodeFormat::looksInternal('3UC4779'));
    }
}
