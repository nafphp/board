<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use SensitiveParameter;

/** Board-bound, expiring account invitations and the authorized account directory. */
interface InvitationServiceInterface
{
    public function create(int $project, array $data): array;

    public function find(#[SensitiveParameter] string $token): array;

    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] array $data): array;

    public function revoke(int $project, int $invitation): void;

    public function pending(int $project): array;

    public function search(int $project, string $query): array;

    public function targets(): array;
}
