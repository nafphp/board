<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Estimation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The estimation scales, read without a database.
 *
 * A stored estimate is a plain number; the scale a project chose decides what
 * that number means and how it is written. Everything here is about that
 * reading, which is why none of it needs storage.
 */
final class EstimationTest extends TestCase
{
    public function testAnUnknownScaleFallsBackToNone(): void
    {
        $this->assertSame('none', Estimation::scale('no-such-scale'));
        $this->assertSame('none', Estimation::scale(null));
        $this->assertSame('none', Estimation::scale(42));
        $this->assertFalse(Estimation::available('no-such-scale'));
    }

    public function testTheShippedScalesAreOffered(): void
    {
        $this->assertTrue(Estimation::available('complexity'));
        $this->assertTrue(Estimation::available('points'));
        $this->assertTrue(Estimation::available('tshirt'));
        $this->assertFalse(Estimation::active('none'));
        $this->assertTrue(Estimation::active('points'));
    }

    public function testStoryPointsCountInTheirUnitAndAreNotNamed(): void
    {
        $this->assertSame([1, 2, 3, 5, 8, 13, 21], Estimation::values('points'));
        $this->assertSame('SP', Estimation::unit('points'));
        $this->assertFalse(Estimation::named('points'));
        $this->assertSame('8', Estimation::display('points', 8));
    }

    /**
     * T-shirt sizes are stored as the numbers of the points scale so a column
     * still sums and a project can switch scales without losing an estimate.
     * What changes is only how the number is written.
     */
    public function testTshirtSizesAreNamedAndSpacedLikePoints(): void
    {
        $this->assertSame([1, 2, 3, 5, 8], Estimation::values('tshirt'));
        $this->assertTrue(Estimation::named('tshirt'));
        $this->assertSame('XS', Estimation::display('tshirt', 1));
        $this->assertSame('M', Estimation::display('tshirt', 3));
        $this->assertSame('XL', Estimation::display('tshirt', 8));
    }

    public function testAValueWithoutANameIsShownAsItsNumber(): void
    {
        // 13 is a points value; on the t-shirt scale it is off-scale and keeps
        // its number rather than borrowing the nearest size's name.
        $this->assertSame('13', Estimation::display('tshirt', 13));
    }

    public function testAValueTheScaleDoesNotOfferIsOffScale(): void
    {
        $this->assertTrue(Estimation::offScale('tshirt', 13));
        $this->assertFalse(Estimation::offScale('points', 13));
        $this->assertFalse(Estimation::offScale('tshirt', null), 'no estimate is not an off-scale one');
        $this->assertFalse(Estimation::offScale('none', 13), 'without a scale nothing is off it');
    }

    /**
     * @return array<string,array{int,int}>
     */
    public static function pointsNeighbours(): array
    {
        return [
            'exact value stays'     => [8, 8],
            'below the smallest'    => [0, 1],
            'above the largest'     => [99, 21],
            'a tie takes the lower' => [4, 3],
            'nearer the upper'      => [7, 8],
            'nearer the lower'      => [10, 8],
        ];
    }

    #[DataProvider('pointsNeighbours')]
    public function testRemappingPicksTheNearestOfferedValue(int $value, int $expected): void
    {
        $this->assertSame($expected, Estimation::nearest('points', $value));
    }

    public function testRemappingOntoTheComplexityScaleCapsAtItsLargest(): void
    {
        // 13 is a points value; complexity offers 1 to 5, so it becomes 5.
        $this->assertSame(5, Estimation::nearest('complexity', 13));
        $this->assertSame([1, 2, 3, 4, 5], Estimation::values('complexity'));
    }

    public function testWithoutAScaleAValueIsLeftAlone(): void
    {
        $this->assertSame(17, Estimation::nearest('none', 17));
        $this->assertSame(13, Estimation::nearest('none', 13));
    }
}
