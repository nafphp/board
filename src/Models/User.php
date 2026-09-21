<?php

declare(strict_types=1);

namespace Naf\Board\Models;

use Naf\Auth\Identity\UserProfile;
use Naf\ORM\Model\AbstractModel;
use Naf\Rbac\Identity\GrantedInterface;
use Naf\Rbac\Identity\ResolvesGrants;

/** @internal */
final class User extends AbstractModel implements GrantedInterface
{
    use ResolvesGrants;

    protected string $name               = '';
    protected string $email              = '';
    protected ?string $password_hash     = null;
    protected int $active                = 1;
    protected string $created_at         = '';
    protected int $security_version      = 0;
    protected ?string $email_verified_at = null;

    public function securityVersion(): int
    {
        return $this->security_version;
    }

    public function getIdentifier(): string
    {
        return (string) $this->getId();
    }

    public function isActive(): bool
    {
        return $this->active === 1;
    }

    public function getProfile(): UserProfile
    {
        return new UserProfile($this->name, $this->email, $this->email_verified_at !== null);
    }
}
