<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Board\Services\AiService;
use Naf\Board\Services\RoleService;

use function Naf\app;

/**
 * A project whose member may comment and upload, and deliberately may not write
 * tickets -- the shape every question about the assistant's boundaries needs.
 *
 * Two classes build on this, because asking what the catalog offers and actually
 * calling a tool cannot run the same way: a call passes the rate limiter, which
 * refuses to count inside a transaction, so those tests commit and rebuild.
 */
abstract class AiProjectTestCase extends BoardTestCase
{
    protected AiService $ai;
    protected int $project;
    protected int $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai      = app()->container()->make(AiService::class);
        $roles         = app()->container()->make(RoleService::class);
        $this->project = $this->projects->create([
            'name'        => 'AI boundaries',
            'description' => 'Tool boundary regression',
        ]);
        $this->elsewhere = $this->projects->create([
            'name'        => 'AI elsewhere',
            'description' => 'A project the member is not in',
        ]);

        $roles->save($this->project, [
            'name'        => 'Redaktion',
            'description' => '',
            'permissions' => ['comment', 'upload'],
        ]);
        $role = $roles->list($this->project)[0];
        $this->projects->member($this->project, [
            'email' => 'member@example.test',
            'role'  => 'custom:' . $role['id'],
        ]);
    }

    /** @return array<string,mixed> */
    protected function createCall(): array
    {
        $board = $this->query->board($this->project);

        return [
            'name'      => 'nafinity_ticket_create',
            'arguments' => [
                'title'          => 'AI test',
                'description'    => 'Confirmed action',
                'priority'       => 'normal',
                'column_id'      => (int) $board['columns'][0]['id'],
                'swimlane_id'    => (int) $board['swimlanes'][0]['id'],
                'board_revision' => (int) $board['board']['revision'],
            ],
        ];
    }
}
