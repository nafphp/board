<?php

declare(strict_types=1);

namespace App\Models;

use Naf\ORM\Model\AbstractModel;

final class Ticket extends AbstractModel
{
    protected int $project_id           = 0;
    protected int $board_id             = 0;
    protected int $column_id            = 0;
    protected int $swimlane_id          = 0;
    protected int $number               = 0;
    protected string $title             = '';
    protected string $description       = '';
    protected ?string $description_html = null;
    protected ?string $start_date       = null;
    protected ?int $estimate_minutes    = null;
    protected int $spent_minutes        = 0;
    protected ?int $estimate_points     = null;
    protected string $priority          = '';
    protected string $color             = '';
    protected ?string $due_date         = null;
    protected string $status            = '';
    protected int $created_by           = 0;
    protected string $created_at        = '';
    protected string $updated_at        = '';
    protected ?string $closed_at        = null;
    protected ?string $archived_at      = null;
    protected int $position             = 0;
    protected int $version              = 0;

    public function getTableName(bool $singular = false): string
    {
        return $singular ? 'ticket' : 'tickets';
    }
}
