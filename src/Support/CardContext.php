<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Estimation;

use function Naf\Board\extensions;

/**
 * Everything a card needs that is not the card itself.
 *
 * Assembled here rather than in the board template because two places render
 * cards: the page, and the endpoint that hands them out one at a time for a
 * live update. Built twice, the two would drift -- a priority symbol added in
 * one, an estimation scale read differently in the other -- and a card would
 * mean something else depending on how it arrived.
 *
 * It is presentation, not domain: lookups a loop would otherwise rebuild per
 * card, and the few decisions a card asks about the viewer.
 *
 * @internal
 */
final class CardContext
{
    /**
     * @param  array<string, mixed> $view what the board query handed the template
     * @return array<string, mixed>
     */
    public static function build(array $view): array
    {
        $project  = $view['project'];
        $scope    = $view['scope'];
        $cards    = $view['cards'] ?? [];
        $filters  = $view['filters'] ?? [];
        $total    = (int) ($view['total'] ?? count($cards));
        $canWrite = $scope->allows('write');
        $scale    = Estimation::scale($project['estimation_scale']);

        return [
            'canDrag'  => $canWrite && !$filters && $total <= self::LIMIT,
            'canWrite' => $canWrite,
            'project'  => $project,
            // The board addresses its own project by id in every card link.
            'projectId'       => $project['id'],
            'running_timers'  => $view['running_timers'] ?? [],
            'prioritySymbols' => array_map(
                static fn($priority) => Icon::mark($priority->icon, 'sm'),
                extensions()->priorities()->all(),
            ),
            /*
             * Drawing a priority nobody declared as one of the known symbols
             * would be a guess, and failing over a single card would be worse
             * than admitting that one value is not understood. `info` rather
             * than a question mark: the icon font is subset to what the
             * interface uses, and a glyph that is not in it renders as its own
             * name.
             */
            'unknownPriority' => Icon::mark('info', 'sm'),
            'boardSlot'       => new BoardSlotContext(
                $view['uiContext'],
                $project,
                $scope,
                array_column($view['labels'] ?? [], null, 'id'),
                array_column($view['members'] ?? [], null, 'id'),
                $view['card_metadata'] ?? [],
                $view['token'],
            ),
            'tags'         => $view['tags'] ?? [],
            'labelMap'     => array_column($view['labels'] ?? [], null, 'id'),
            'estimating'   => Estimation::active($scale),
            'scale'        => $scale,
            'estimateUnit' => Estimation::unit($scale),
            'assignments'  => $view['assignments'] ?? [],
            'memberMap'    => array_column($view['members'] ?? [], null, 'id'),
        ];
    }

    /**
     * Beyond this many results the board stops offering drag and drop.
     *
     * The same number the page uses for its "begrenzte Ansicht" hint: a board
     * that is not showing everything cannot say where a card would land.
     */
    public const int LIMIT = 300;
}
