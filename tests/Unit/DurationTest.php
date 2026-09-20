<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Durations are typed by people, in the shorthand people use.
 *
 * Nobody writes 160 when they mean two hours and forty minutes, so the field
 * accepts what they would say instead -- and what it shows afterwards is a form
 * that may be typed straight back in.
 */
final class DurationTest extends TestCase
{
    /**
     * @return array<string,array{string,int}>
     */
    public static function writtenForms(): array
    {
        return [
            'hours and minutes'        => ['2h 40m', 160],
            'without the space'        => ['2h40m', 160],
            'hours only'               => ['2h', 120],
            'minutes only'             => ['40m', 40],
            'minutes written out'      => ['45min', 45],
            'clock notation'           => ['1:30', 90],
            'a bare number is minutes' => ['90', 90],
            'a decimal hour'           => ['1.5h', 90],
            'a decimal with a comma'   => ['1,5h', 90],
            'trailing minutes implied' => ['2h30', 150],
            'nothing at all'           => ['0', 0],
            'spaces and capitals'      => [' 3H 5M ', 185],
            'unit left off the end'    => ['2h 40', 160],
        ];
    }

    #[DataProvider('writtenForms')]
    public function testAWrittenDurationIsUnderstood(string $written, int $minutes): void
    {
        $this->assertSame($minutes, Duration::parse($written, 'spent_minutes'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function nonsense(): array
    {
        return [
            'a decimal without a unit'        => ['1.5'],
            'words'                           => ['zwei Stunden'],
            'more minutes than an hour holds' => ['1:75'],
            'negative'                        => ['-5m'],
            'longer than any project'         => ['99999999h'],
            'arithmetic'                      => ['2h-3m'],
            'trailing rubbish'                => ['2h 40x'],
        ];
    }

    #[DataProvider('nonsense')]
    public function testSomethingThatIsNotADurationIsRefused(string $written): void
    {
        $this->expectException(Failure::class);

        Duration::parse($written, 'spent_minutes');
    }

    /** What is shown is what may be typed back in. */
    public function testWhatIsShownCanBeTypedBackIn(): void
    {
        $this->assertSame('2h 40m', Duration::format(160));
        $this->assertSame('2h', Duration::format(120));
        $this->assertSame('40m', Duration::format(40));
        $this->assertSame('0m', Duration::format(0), 'zero is not written as an hour');
    }

    #[DataProvider('writtenForms')]
    public function testEveryFormattedDurationParsesBackToItself(string $written, int $minutes): void
    {
        $this->assertSame($minutes, Duration::parse(Duration::format($minutes), 'spent_minutes'));
    }
}
