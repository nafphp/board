<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use InvalidArgumentException;

/**
 * One field of a ticket, core or contributed.
 *
 * Identity is the key, never the label. Core fields keep adapters to their
 * existing services; plugin keys are stored as ticket metadata.
 */
final readonly class TicketFieldDefinition
{
    /** Attributes a contributed field may never claim. */
    public const array RESERVED_KEYS = [
        'id',
        'project_id',
        'created_by',
        'version',
        'board_revision',
        'number',
        'ticket_key',
    ];

    /** Groups that exist without an extra panel registration. */
    public const array CORE_GROUPS = ['primary', 'details', 'planning', 'information'];

    /**
     * @param string      $key             Literal field key, namespaced for plugins
     * @param string      $label           Translated label
     * @param string      $type            Registered field type id
     * @param string      $group           Group id pointing at a registered panel
     * @param int         $index           Sort value inside the group, ascending
     * @param mixed       $default         Value used when nothing is stored
     * @param bool        $nullable        Whether null is a valid value
     * @param bool        $required        Whether an effective value is required
     * @param bool        $readOnly        Whether the field is never writable
     * @param string      $readPermission  Project action required to read
     * @param string      $writePermission Project action required to write
     * @param array       $options         Type options
     * @param bool        $showOnCreate    Whether the create form offers it
     * @param string|null $adapter         Container id of a TicketFieldAdapterInterface
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $group = 'details',
        public int $index = 100,
        public mixed $default = null,
        public bool $nullable = true,
        public bool $required = false,
        public bool $readOnly = false,
        public string $readPermission = 'read',
        public string $writePermission = 'write',
        public array $options = [],
        public bool $showOnCreate = true,
        public ?string $adapter = null,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('A ticket field needs a non-empty key.');
        }

        if ($adapter === null && in_array($key, self::RESERVED_KEYS, true)) {
            throw new InvalidArgumentException(
                'Ticket field "' . $key . '" is a reserved system attribute.',
            );
        }
    }

    /** Whether this field stores its value in ticket_metadata. */
    public function isMetadata(): bool
    {
        return $this->adapter === null;
    }

    public function id(): string
    {
        return $this->key;
    }
}
