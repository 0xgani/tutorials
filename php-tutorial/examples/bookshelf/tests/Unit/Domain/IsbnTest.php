<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Domain;

use Bookshelf\Domain\Isbn;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Isbn::class)]
final class IsbnTest extends TestCase
{
    #[DataProvider('validIsbns')]
    public function testAcceptsValidIsbnsWithOrWithoutSeparators(string $input): void
    {
        $isbn = new Isbn($input);

        self::assertSame('9780441013593', $isbn->value);
    }

    /** @return iterable<string, array{string}> */
    public static function validIsbns(): iterable
    {
        yield 'digits only' => ['9780441013593'];
        yield 'with dashes' => ['978-0-441-01359-3'];
        yield 'with spaces' => ['978 0 441 01359 3'];
    }

    #[DataProvider('invalidIsbns')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ISBN-13');

        new Isbn($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIsbns(): iterable
    {
        yield 'wrong check digit' => ['9780441013594'];
        yield 'too short' => ['978044101359'];
        yield 'too long' => ['97804410135930'];
        yield 'a letter' => ['978044101359X'];
        yield 'empty' => [''];
    }

    public function testFormatsWithADashAfterThePrefix(): void
    {
        self::assertSame('978-0441013593', new Isbn('9780441013593')->format());
    }

    public function testEqualityComparesTheDigits(): void
    {
        self::assertTrue(new Isbn('978-0-441-01359-3')->equals(new Isbn('9780441013593')));
        self::assertFalse(new Isbn('9780441013593')->equals(new Isbn('9780807083697')));
    }
}
