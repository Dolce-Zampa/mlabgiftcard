<?php
declare(strict_types=1);

use Mlab\GiftCard\Crypto;
use Mlab\GiftCard\GiftCardException;
use PHPUnit\Framework\TestCase;

final class CodeFormatTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(str_repeat('a', 64));
    }

    public function test_generated_codes_are_readable_and_normalize_to_the_same_hash(): void
    {
        $crypto = $this->crypto();
        for ($i = 0; $i < 50; ++$i) {
            $code = $crypto->newCode();
            self::assertMatchesRegularExpression('/^GC-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/D', $code);
            self::assertSame($crypto->codeHash($code), $crypto->codeHash(strtolower(str_replace('-', ' ', $code))));
            self::assertSame($crypto->codeHash($code), $crypto->codeHash(substr(str_replace('-', '', $code), 2)));
        }
    }

    public function test_new_code_starting_gc_works_with_and_without_prefix(): void
    {
        $crypto = $this->crypto();
        self::assertSame($crypto->codeHash('GC-GC23-ABCD'), $crypto->codeHash('GC23ABCD'));
    }

    public function test_previous_long_codes_are_rejected(): void
    {
        $crypto = $this->crypto();
        $this->expectException(GiftCardException::class);
        $crypto->codeHash('GC-614ABFDD-7E8A96A1-C4E6CBB2-478D4E08');
    }

    public function test_short_invalid_code_is_rejected(): void
    {
        $this->expectException(GiftCardException::class);
        $this->crypto()->codeHash('GC-1234');
    }
}
