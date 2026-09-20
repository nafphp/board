<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Estimation;
use Naf\Board\Tests\Support\BoardTestCase;

/**
 * A project carries one estimation scale, and the stored number outlives it.
 *
 * Switching the scale reinterprets what is already there rather than moving it
 * into a second field, so nothing is lost by changing your mind -- a value the
 * new scale does not offer stays, is reported, and only an explicit remap moves
 * it. Which is why the scales are spaced alike: the numbers still mean something
 * on the other side.
 */
final class ProjectEstimationTest extends BoardTestCase
{
    private int $project;
    /** @var array<string,mixed> */
    private array $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->projects->create([
            'name'             => 'Estimation',
            'description'      => 'Scale regression',
            'estimation_scale' => 'points',
        ]);
        $this->board = $this->query->board($this->project);
    }

    public function testTheChosenScaleIsStoredAndCanBeChanged(): void
    {
        $this->assertSame('points', $this->storedScale());

        $this->chooseScale('complexity');

        $this->assertSame('complexity', $this->storedScale());
    }

    /**
     * Not an error anyone can reach through the interface, which only offers what
     * exists -- so it falls back to no estimation rather than failing a save.
     */
    public function testAScaleNobodyOffersFallsBackToNone(): void
    {
        $this->chooseScale('velocity');

        $this->assertSame('none', $this->storedScale());
    }

    public function testAnUnrelatedWriteLeavesTheEstimateAlone(): void
    {
        $ticket = $this->addTicket(8);
        $this->assertSame(8, $this->points($ticket), 'the estimate was not stored');

        // The inline field saves on its own, so every other write has to leave it be.
        $this->tickets->update($this->project, $ticket, ['title' => 'Renamed'] + $this->versionOf($ticket));

        $this->assertSame(8, $this->points($ticket));
    }

    public function testAnEstimateCanBeCleared(): void
    {
        $ticket = $this->addTicket(8);

        $this->tickets->update($this->project, $ticket, ['estimate_points' => ''] + $this->versionOf($ticket));

        $this->assertNull($this->points($ticket));
    }

    public function testANumberOutsideWhatTheColumnHoldsIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->addTicket(Estimation::MAX + 1));
        $this->assertDenied(422, fn() => $this->addTicket(-1));
    }

    public function testChangingTheScaleKeepsEveryEstimateAndCountsTheOnesItCannotOffer(): void
    {
        $fits = $this->addTicket(3);
        $over = $this->addTicket(13);

        $this->chooseScale('complexity');

        $this->assertSame(13, $this->points($over), 'the scale change dropped an estimate');
        $this->assertSame(3, $this->points($fits));
        $this->assertSame(
            1,
            $this->projects->offScaleEstimates($this->project, 'complexity'),
            'the off-scale estimate was not counted',
        );
        $this->assertSame(
            0,
            $this->projects->offScaleEstimates($this->project, 'none'),
            'estimates were counted off a scale that offers nothing',
        );
    }

    public function testOnlyAnExplicitRemapMovesANumberAndOnlyTheOnesThatNoLongerFit(): void
    {
        $fits = $this->addTicket(3);
        $over = $this->addTicket(13);

        $this->chooseScale('complexity', remap: true);

        $this->assertSame(5, $this->points($over), 'the off-scale estimate was not remapped');
        $this->assertSame(3, $this->points($fits), 'the remap changed an estimate that fitted');
        $this->assertSame(
            0,
            $this->projects->offScaleEstimates($this->project, 'complexity'),
            'the remap left something behind',
        );
    }

    private function storedScale(): string
    {
        return $this->query->board($this->project)['project']['estimation_scale'];
    }

    private function addTicket(?int $points): int
    {
        return $this->tickets->create($this->project, [
            'title'           => 'Estimated',
            'description'     => 'Carries a number',
            'priority'        => 'normal',
            'column_id'       => $this->board['columns'][0]['id'],
            'swimlane_id'     => $this->board['swimlanes'][0]['id'],
            'estimate_points' => $points,
            'board_revision'  => $this->tickets->board($this->project)['revision'],
        ]);
    }

    private function points(int $ticket): ?int
    {
        $value = $this->tickets->ticket($this->project, $ticket)['estimate_points'];

        return $value === null ? null : (int) $value;
    }

    /** @return array<string,mixed> */
    private function versionOf(int $ticket): array
    {
        return [
            'version'        => $this->tickets->ticket($this->project, $ticket)['version'],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ];
    }

    private function chooseScale(string $scale, bool $remap = false): void
    {
        $this->projects->update($this->project, [
            'name'             => 'Estimation',
            'description'      => 'Scale regression',
            'ticket_key'       => 'EST',
            'estimation_scale' => $scale,
            'color'            => '#6366f1',
            'icon'             => 'E',
            'remap_estimates'  => $remap ? '1' : '',
        ]);
    }
}
