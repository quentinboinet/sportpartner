<?php

namespace App\Tests\Unit\Service\Route;

use App\Service\Route\DurationFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationFormatTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function validInputs(): iterable
    {
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'H:MM' => ['3:15', 11700];
        yield 'HH:MM' => ['03:15', 11700];
        yield 'H:MM:SS' => ['3:15:30', 11730];
        yield 'zero' => ['0:00', 0];
        yield 'over 24 h' => ['30:05', 108300];
        yield 'max hours' => ['999:59:59', 999 * 3600 + 59 * 60 + 59];
        yield 'HhMM' => ['3h15', 11700];
        yield 'HHhMM upper' => ['12H05', 43500];
        yield 'Hh only' => ['3h', 10800];
        yield 'spaces' => [' 3 : 15 : 30 ', 11730];
        yield 'spaces h' => ['3 h 15', 11700];
    }

    #[Test]
    #[DataProvider('validInputs')]
    public function parsesValidDurations(string $input, ?int $expected): void
    {
        self::assertSame($expected, DurationFormat::parse($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'text' => ['abc'];
        yield 'minutes 60' => ['3:60'];
        yield 'seconds 60' => ['3:15:60'];
        yield 'one digit minutes' => ['3:5'];
        yield 'four digit hours' => ['1000:00'];
        yield 'negative' => ['-3:15'];
        yield 'decimal' => ['3.5'];
        yield 'too many parts' => ['1:02:03:04'];
        yield 'h with seconds' => ['3h15m'];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function rejectsGarbage(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DurationFormat::parse($input);
    }

    #[Test]
    public function formatsDurations(): void
    {
        self::assertSame('3:05', DurationFormat::format(11109));
        self::assertSame('3:05:09', DurationFormat::format(11109, true));
        self::assertSame('0:00', DurationFormat::format(0));
        self::assertSame('30:00', DurationFormat::format(108000));
        self::assertSame('-0:05', DurationFormat::format(-300));
        self::assertSame('3:15', DurationFormat::formatInput(11700));
        self::assertSame('3:15:30', DurationFormat::formatInput(11730));
    }

    #[Test]
    public function formatRoundTripsThroughParse(): void
    {
        foreach ([0, 59, 60, 3599, 11730, 108300, 3_599_999] as $seconds) {
            self::assertSame($seconds, DurationFormat::parse(DurationFormat::formatInput($seconds)));
        }
    }

    #[Test]
    public function formatsPace(): void
    {
        self::assertSame('5:07', DurationFormat::formatPace(307.4));
        self::assertSame('5:08', DurationFormat::formatPace(307.5));
        self::assertSame('4:00', DurationFormat::formatPace(240.0));
        self::assertSame('12:30', DurationFormat::formatPace(750.0));
    }

    #[Test]
    public function formatsClockWithDayOffset(): void
    {
        $start = new \DateTimeImmutable('1970-01-01 06:00:00');

        self::assertSame(['time' => '06:00', 'dayOffset' => 0], DurationFormat::formatClock($start, 0));
        self::assertSame(['time' => '09:15', 'dayOffset' => 0], DurationFormat::formatClock($start, 11700));
        self::assertSame(['time' => '02:00', 'dayOffset' => 1], DurationFormat::formatClock($start, 20 * 3600));
        self::assertSame(['time' => '06:00', 'dayOffset' => 2], DurationFormat::formatClock($start, 48 * 3600));
        self::assertSame(['time' => '23:59', 'dayOffset' => 0], DurationFormat::formatClock($start, 18 * 3600 - 1));
    }
}
