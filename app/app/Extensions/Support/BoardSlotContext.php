<?php

declare(strict_types=1);

namespace Nafinity\Support;

use App\Domain\ProjectScope;

/**
 * The board, and where on it a contribution is being rendered.
 *
 * The board loads every card's metadata in one query, so a badge reads what is
 * already here instead of asking again per card.
 */
final readonly class BoardSlotContext implements SlotContextInterface
{
    /**
     * @param UiContext    $ui       The authorized rendering context
     * @param array        $project  The project row
     * @param ProjectScope $scope    Rights of the current actor
     * @param array        $labels   Label rows, keyed by id
     * @param array        $members  Member rows, keyed by id
     * @param array        $metadata Ticket id to its readable metadata
     * @param string       $token    The CSRF token of this page
     * @param array|null   $card     The card being rendered, in a card slot
     * @param array|null   $column   The column being rendered, in a column slot
     */
    public function __construct(
        private UiContext $ui,
        public array $project,
        public ProjectScope $scope,
        public array $labels,
        public array $members,
        public array $metadata,
        public string $token,
        public ?array $card = null,
        public ?array $column = null,
    ) {
    }

    public function ui(): UiContext
    {
        return $this->ui;
    }

    public function projectId(): int
    {
        return (int) $this->project['id'];
    }

    /**
     * One metadata value of the card being rendered
     *
     * @param string $key     Field key
     * @param mixed  $default Returned when the card has no such value
     */
    public function value(string $key, mixed $default = null): mixed
    {
        $values = $this->metadata[(int) ($this->card['id'] ?? 0)] ?? [];

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * @param array $card The card to render for
     */
    public function withCard(array $card): self
    {
        return new self(
            $this->ui,
            $this->project,
            $this->scope,
            $this->labels,
            $this->members,
            $this->metadata,
            $this->token,
            $card,
            $this->column,
        );
    }

    /**
     * @param array $column The column to render for
     */
    public function withColumn(array $column): self
    {
        return new self(
            $this->ui,
            $this->project,
            $this->scope,
            $this->labels,
            $this->members,
            $this->metadata,
            $this->token,
            $this->card,
            $column,
        );
    }
}
