<?php

declare(strict_types=1);

namespace Naf\Board\Rbac;

use Naf\Rbac\Definition\PermissionDefinition;
use Naf\Rbac\Definition\RoleDefinition;
use Naf\Rbac\Permissions\Everything;
use Naf\Rbac\Registry\PermissionRegistry;
use Naf\Rbac\Registry\RoleRegistry;

/**
 * What somebody may do with the installation, as opposed to within a board.
 *
 * The two questions are separate and stay separate: what a person may do *in a
 * project* is answered by the project's own roles, and moving those in here is
 * a migration of live data rather than a second declaration. Declaring a
 * Maintainer role now, while the board still enforces project access its own
 * way, would put a role in the editor that looks like it grants something and
 * does not -- so the roles that belong to a board arrive when their permissions
 * do.
 *
 * @internal
 */
final class Installation
{
    public const string VIEW_USERS      = 'users.view';
    public const string MANAGE_USERS    = 'users.manage';
    public const string IMPERSONATE     = 'users.impersonate';
    public const string CREATE_PROJECTS = 'projects.create';
    public const string ADMIN_PROJECTS  = 'projects.administer';
    public const string MANAGE_SETTINGS = 'settings.manage';

    /*
     * Reading the installation's own history is its own right, and not part of
     * managing anything. A log that records who changed a role is a log that
     * must not be readable by everyone whose role was changed.
     */
    public const string VIEW_AUDIT = 'audit.view';

    /*
     * The entries about a person rather than about their work: when their
     * password last changed, when they moved their address. Reading the log at
     * all and reading those are two different questions, and somebody who may
     * follow what happened on the boards need not be told when a colleague last
     * changed a password.
     */
    public const string VIEW_PERSONAL_AUDIT = 'audit.personal';

    /**
     * What somebody is called in a board they administer without being in it.
     *
     * Not a membership role -- there is no membership. It is what the interface
     * says instead of leaving the slot blank, and both the board's own badge and
     * the project list read it from here so they cannot disagree.
     */
    public const string ADMIN_ROLE_NAME = 'Administrator';

    public static function declare(PermissionRegistry $permissions, RoleRegistry $roles): void
    {
        $permissions->add(
            new PermissionDefinition(
                self::VIEW_USERS,
                'Nutzer sehen',
                'Die Liste der Konten dieser Installation einsehen.',
                'Nutzer',
                10,
            ),
            new PermissionDefinition(
                self::MANAGE_USERS,
                'Nutzer verwalten',
                'Konten anlegen, einladen und deaktivieren.',
                'Nutzer',
                20,
            ),
            new PermissionDefinition(
                self::IMPERSONATE,
                'Als jemand anderes ansehen',
                'Die Anwendung vorübergehend mit den Rechten einer anderen Person sehen. '
                . 'Jede Änderung dabei wird der eigenen Person zugeschrieben.',
                'Nutzer',
                30,
            ),
            new PermissionDefinition(
                self::CREATE_PROJECTS,
                'Projekte anlegen',
                'Neue Boards erstellen.',
                'Projekte',
                10,
            ),
            new PermissionDefinition(
                self::ADMIN_PROJECTS,
                'Alle Projekte verwalten',
                'Jedes Board sehen und verwalten, auch ohne Mitgliedschaft.',
                'Projekte',
                20,
            ),
            new PermissionDefinition(
                self::MANAGE_SETTINGS,
                'Installation einstellen',
                'Mailversand, Grenzen und andere Vorgaben dieser Installation.',
                'Installation',
                10,
            ),
            new PermissionDefinition(
                self::VIEW_AUDIT,
                'Protokoll lesen',
                'Die aufgezeichneten Änderungen dieser Installation einsehen, auch außerhalb der Boards.',
                'Installation',
                20,
            ),
            new PermissionDefinition(
                self::VIEW_PERSONAL_AUDIT,
                'Persönliche Vorgänge im Protokoll lesen',
                'Auch Passwort- und E-Mail-Änderungen einzelner Konten sehen.',
                'Installation',
                30,
            ),
        );

        /*
         * The administrator is the role that may do everything, so it carries
         * `rbac.all` rather than a list somebody has to keep current. The rest
         * of the list stays spelled out on purpose: `holdersOfPermission` counts
         * stored rows, so the guard that refuses to remove the last person who
         * can manage roles needs to see rbac.manage written down here.
         */
        $roles->add(new RoleDefinition(
            'admin',
            'Administrator',
            'Darf alles. Verwaltet diese Installation: Nutzer, Rollen und Vorgaben.',
            [
                Everything::KEY,
                'rbac.manage',
                'rbac.manage.own',
                self::VIEW_USERS,
                self::MANAGE_USERS,
                self::IMPERSONATE,
                self::CREATE_PROJECTS,
                self::ADMIN_PROJECTS,
                self::MANAGE_SETTINGS,
                self::VIEW_AUDIT,
                self::VIEW_PERSONAL_AUDIT,
            ],
            index: 10,
        ));

        /*
         * What an ordinary account may do, which until now was a hardcoded list
         * on the user model. Declaring it changes nothing by itself -- somebody
         * has to hold it -- but it makes "everyone may create boards" a decision
         * an installation can revisit rather than a line in the code.
         */
        $roles->add(new RoleDefinition(
            'user',
            'Nutzer',
            'Darf sich anmelden und eigene Boards anlegen.',
            [self::CREATE_PROJECTS],
            index: 90,
        ));
    }
}
