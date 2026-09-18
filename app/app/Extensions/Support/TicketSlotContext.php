<?php

declare(strict_types=1);

namespace Nafinity\Support;

use App\Domain\ProjectScope;
use Nafinity\Definition\TicketFieldDefinition;

/**
 * One ticket, as a contribution to it sees it.
 *
 * This is the whole contract of the ticket slots. A contributed panel or widget
 * is written against these names; it does not have to know which local
 * variables the surrounding view happens to have.
 *
 * Everything here has already been authorized: the metadata holds only values
 * this actor may read, and the field definitions only the ones they may see.
 */
final readonly class TicketSlotContext implements SlotContextInterface
{
    /**
     * @param UiContext                          $ui         The authorized rendering context
     * @param array                              $ticket     The ticket row, empty-ish while creating
     * @param array                              $project    The project row
     * @param ProjectScope                       $scope      Rights of the current actor
     * @param array                              $board      The board row, with its revision
     * @param array                              $params     Route parameters of this ticket
     * @param string                             $token      The CSRF token of this page
     * @param bool                               $editable   Whether this actor may write here
     * @param bool                               $isNew      Whether the ticket does not exist yet
     * @param array                              $columns    Board columns
     * @param array                              $swimlanes  Board swimlanes
     * @param array                              $labels     Project labels
     * @param array                              $members    Project members
     * @param array<string, mixed>               $metadata   Readable metadata values
     * @param array<string, TicketFieldDefinition> $fields   Readable metadata definitions
     * @param array                              $links      Linked tickets
     * @param array                              $attachments Attachment rows
     * @param array                              $activity   Recent activity of this ticket
     * @param array                              $timer      Timer state of this ticket
     * @param array                              $preferences The actor's own preferences
     * @param string                             $creator    Name of whoever created it
     * @param InlineFieldRenderer                $field      The existing inline field editor
     */
    public function __construct(
        private UiContext $ui,
        public array $ticket,
        public array $project,
        public ProjectScope $scope,
        public array $board,
        public array $params,
        public string $token,
        public bool $editable,
        public bool $isNew,
        public array $columns,
        public array $swimlanes,
        public array $labels,
        public array $members,
        public array $metadata,
        public array $fields,
        public array $links,
        public array $attachments,
        public array $activity,
        public array $timer,
        public array $preferences,
        public string $creator,
        public InlineFieldRenderer $field,
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

    public function ticketId(): ?int
    {
        return isset($this->ticket['id']) ? (int) $this->ticket['id'] : null;
    }

    /**
     * One readable metadata value, or the field's own default
     *
     * @param string $key     Field key
     * @param mixed  $default Returned when neither a value nor a definition exists
     */
    public function value(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->metadata)) {
            return $this->metadata[$key];
        }

        return $this->fields[$key]->default ?? $default;
    }

    /**
     * The readable contributed fields of one sidebar group
     *
     * @param string $group Group id
     *
     * @return array<string, TicketFieldDefinition>
     */
    public function fieldsIn(string $group): array
    {
        return array_filter(
            $this->fields,
            static fn(TicketFieldDefinition $field) => $field->group === $group,
        );
    }
}
