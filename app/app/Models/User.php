<?php

declare(strict_types=1);

namespace App\Models;

use Naf\Auth\Identity\UserInterface;
use Naf\Auth\Identity\UserProfile;
use Naf\ORM\Model\AbstractModel;

final class User extends AbstractModel implements UserInterface
{
    protected string $name           = '';
    protected string $email          = '';
    protected ?string $password_hash = null;
    protected int $active            = 1;
    protected string $global_role    = 'user';
    protected string $created_at     = '';

    public function getIdentifier(): string
    {
        return (string) $this->getId();
    }

    public function getRoles(): iterable
    {
        return [$this->global_role];
    }

    public function getPermissions(): iterable
    {
        return ['projects.create'];
    }

    public function isActive(): bool
    {
        return $this->active === 1;
    }

    public function getProfile(): UserProfile
    {
        return new UserProfile($this->name, $this->email, false);
    }
}
