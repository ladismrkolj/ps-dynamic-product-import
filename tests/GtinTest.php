<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\Gtin;

class GtinTest extends TestCase
{
    public function testValidEan13(): void
    {
        self::assertTrue(Gtin::isValidEan13('4006381333931'));
        self::assertTrue(Gtin::isValidEan13('5901234123457'));
        self::assertTrue(Gtin::isValidEan13('0012345678905'));
    }

    public function testEan8IsRejected(): void
    {
        self::assertFalse(Gtin::isValidEan13('96385074'));
    }

    public function testBadCheckDigit(): void
    {
        self::assertFalse(Gtin::isValidEan13('4006381333932'));
    }

    public function testWrongLengths(): void
    {
        self::assertFalse(Gtin::isValidEan13(''));
        self::assertFalse(Gtin::isValidEan13('400638133393'));
        self::assertFalse(Gtin::isValidEan13('40063813339310'));
        // 12-digit UPC-A is not padded.
        self::assertFalse(Gtin::isValidEan13('012345678905'));
    }

    public function testNonDigits(): void
    {
        self::assertFalse(Gtin::isValidEan13('400638133393a'));
        self::assertFalse(Gtin::isValidEan13('4.006381333931E+12'));
        self::assertFalse(Gtin::isValidEan13("4006381333931\n"));
    }

    public function testWarning(): void
    {
        self::assertNull(Gtin::warning(null));
        self::assertNull(Gtin::warning(''));
        self::assertNull(Gtin::warning(' 4006381333931 '));
        self::assertSame('invalid EAN-13 "4006381333932" (check digit) — not written', Gtin::warning('4006381333932'));
        // A JSON number that lost its leading zero.
        self::assertSame('invalid EAN-13 "12345678905" (length 11) — not written', Gtin::warning(12345678905));
        self::assertStringContainsString('not digits only', (string) Gtin::warning('4.006381333931E+12'));
    }
}
