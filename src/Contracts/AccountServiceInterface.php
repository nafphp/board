<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Naf\Auth\Credentials\PasswordCredentials;
use SensitiveParameter;

/**
 * Sign-in, password, e-mail change and session revocation of one's own account.
 */
interface AccountServiceInterface
{
    public function authenticate(PasswordCredentials $credentials, string $provider): bool;

    /**
     * Open an account for somebody else, which needs the right for it.
     *
     * @param array<string, mixed> $input
     */
    public function create(#[SensitiveParameter] array $input): int;

    public function profile(): array;

    public function changePassword(#[SensitiveParameter] array $input): void;

    public function requestEmail(#[SensitiveParameter] array $input): array;

    public function confirmEmail(#[SensitiveParameter] array $input): void;

    public function cancelEmail(): void;

    public function cleanup(): void;
}
