<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * One scale per project, summed over every column.
 *
 * Switching the scale reinterprets the numbers rather than moving them, so a
 * value the new scale does not offer stays, is marked on its card, and is
 * reported in the settings with a one-off remap on offer. Nothing moves unless
 * somebody asks for it.
 *
 * The scale is put back at the end: this is the seeded demo project, and the
 * phases after this read it.
 */
final class EstimationOverHttpTest extends AcceptanceTestCase
{
    protected function tearDown(): void
    {
        $this->setScale('points');
        parent::tearDown();
    }

    public function testTheColumnSumsAccountForExactlyTheEstimatesOnTheBoard(): void
    {
        $this->setScale('points');
        $board = $this->page($this->alice, '/projects/' . self::PROJECT);

        preg_match_all('/<span data-points-value>(\d+)<\/span>/', $board, $sums);
        preg_match_all('/class="ticket-card"[^>]*data-points="(\d+)"/s', $board, $cards);

        $this->assertCount(4, $sums[1], 'there is not a sum for every column');
        $this->assertGreaterThan(0, array_sum(array_map('intval', $cards[1])), 'no card carries an estimate');
        $this->assertSame(
            array_sum(array_map('intval', $cards[1])),
            array_sum(array_map('intval', $sums[1])),
            'the sums and the cards disagree',
        );
        $this->assertStringContainsString('SP</small>', $board, 'the unit is missing beside the sum');
    }

    public function testASmallerScaleReportsWhatItCannotOfferAndMarksItOnTheCards(): void
    {
        $this->givenAnEstimateOf(13);

        $this->setScale('complexity');

        $settings = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        // The sentence, not the class it is styled with: settings-hint is the
        // shared note style and says nothing about which note is on the page.
        $this->assertStringContainsString(
            'die diese Skala nicht anbietet',
            $settings,
            'nothing was reported',
        );
        $this->assertStringContainsString('name="remap_estimates"', $settings, 'no remap was offered');
        $this->assertStringContainsString(
            'off-scale',
            $this->page($this->alice, '/projects/' . self::PROJECT),
            'the off-scale estimates are not marked',
        );
    }

    public function testTheRemapMovesEveryOffScaleEstimateOnce(): void
    {
        $this->givenAnEstimateOf(13);
        $this->setScale('complexity');

        $this->setScale('complexity', remap: true);

        $this->assertStringNotContainsString(
            'die diese Skala nicht anbietet',
            $this->page($this->alice, '/projects/' . self::PROJECT . '/settings'),
            'something is still off the scale',
        );
        $this->assertStringNotContainsString(
            'off-scale',
            $this->page($this->alice, '/projects/' . self::PROJECT),
        );
    }

    /** Turning it off hides the counter. The numbers are still there. */
    public function testTurningEstimationOffAndOnAgainKeepsTheNumbers(): void
    {
        $this->setScale('none');
        $this->assertStringNotContainsString(
            'data-points-value',
            $this->page($this->alice, '/projects/' . self::PROJECT),
            'the counter survived being turned off',
        );

        $this->setScale('points');

        preg_match_all(
            '/<span data-points-value>(\d+)<\/span>/',
            $this->page($this->alice, '/projects/' . self::PROJECT),
            $sums,
        );
        $this->assertGreaterThan(0, array_sum(array_map('intval', $sums[1])), 'the estimates did not come back');
    }

    /**
     * A ticket carrying a number the smaller scale will not offer.
     *
     * Created rather than assumed: the remap below really does rewrite the
     * numbers, so a test that relied on the seeded 8 and 13 would pass or fail
     * depending on whether the remap had run yet.
     */
    private function givenAnEstimateOf(int $points): void
    {
        $this->setScale('points');
        $this->createTicket(['title' => 'Off the smaller scale', 'estimate_points' => $points]);
    }

    private function setScale(string $scale, bool $remap = false): void
    {
        $body = [
            'name'             => 'Nafinity',
            'description'      => 'Ein klarer Ort für Ideen, Entscheidungen und die nächste gute Version.',
            'ticket_key'       => 'NAF',
            'color'            => '#6366f1',
            'icon'             => 'N',
            'estimation_scale' => $scale,
        ];
        if ($remap) {
            $body['remap_estimates'] = '1';
        }

        $saved = $this->post($this->alice, '/projects/' . self::PROJECT . '/settings', $body);
        $this->assertSame(200, $saved['status'], 'the scale ' . $scale . ' was refused');
    }
}
