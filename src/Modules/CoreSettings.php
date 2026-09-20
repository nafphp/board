<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\SettingDefinition;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Domain\Estimation;
use Naf\Board\ExtensionContext;
use Naf\Board\Modules\Providers\AiSectionProvider;
use Naf\Board\Modules\Providers\MembersSectionProvider;
use Naf\Board\Modules\Providers\PersonalSectionProvider;
use Naf\Board\Modules\Providers\ProjectSectionProvider;
use Naf\Board\Modules\Providers\RolesSectionProvider;
use Naf\Board\Modules\Providers\StructureSectionProvider;
use Naf\Board\Modules\Providers\UsersSectionProvider;
use Naf\Board\Support\Locales;

/**
 * The settings cards and values Nafinity already had.
 *
 * Every existing card keeps its id and its own form. The values behind them
 * keep their tables too: these definitions describe where a value lives, they
 * do not move it.
 *
 * @internal
 */
final class CoreSettings implements ExtensionProviderInterface
{
    /** The four time zones the personal form has always offered. */
    private const array ZONES = ['Europe/Berlin', 'Europe/London', 'America/New_York', 'UTC'];

    public function register(ExtensionContext $context): void
    {
        $this->sections($context);
        $this->personal($context);
        $this->project($context);
        $this->application($context);
    }

    private function sections(ExtensionContext $context): void
    {
        $sections = $context->settingSections();

        $sections->add(new SettingSection(
            'personal',
            'user',
            'Persönlich',
            'settings/personal',
            PersonalSectionProvider::class,
            100,
            null,
            'person',
        ));
        $sections->add(new SettingSection(
            'ai',
            'user',
            'Lokale AI',
            'settings/ai',
            AiSectionProvider::class,
            200,
            null,
            'auto_awesome',
        ));
        $sections->add(new SettingSection(
            'general',
            'project',
            'Allgemein',
            'settings/project',
            ProjectSectionProvider::class,
            300,
            null,
            'tune',
        ));
        $sections->add(new SettingSection(
            'roles',
            'project',
            'Rollen & Rechte',
            'settings/roles',
            RolesSectionProvider::class,
            400,
            null,
            'shield',
        ));
        $sections->add(new SettingSection(
            'users',
            'project',
            'Mitglieder',
            'settings/members',
            MembersSectionProvider::class,
            500,
            'members',
            'group',
        ));

        $index = 600;

        foreach (
            [
                'column'   => ['Spalten', 'view_week'],
                'swimlane' => ['Swimlanes', 'table_rows'],
                'label'    => ['Labels', 'label'],
            ] as $id => [$label, $icon]
        ) {
            $sections->add(new SettingSection(
                $id,
                'project',
                $label,
                'settings/structure',
                StructureSectionProvider::class,
                $index,
                'structure',
                $icon,
            ));
            $index += 100;
        }

        $sections->add(new SettingSection(
            'project_personal',
            'project_user',
            'Für mich in diesem Projekt',
            null,
            null,
            900,
            null,
            'notifications',
        ));
    }

    private function personal(ExtensionContext $context): void
    {
        $settings = $context->settings();
        $zones    = array_combine(self::ZONES, self::ZONES);

        $settings->add(new SettingDefinition(
            'theme',
            'user',
            'personal',
            'Darstellung',
            'select',
            'system',
            100,
            [
                'choices' => [
                    'system' => 'Wie mein Gerät',
                    'light'  => 'Hell',
                    'dark'   => 'Dunkel',
                ],
                'legacy' => ['store' => 'user_preferences', 'column' => 'theme'],
            ],
        ));
        $settings->add(new SettingDefinition(
            'locale',
            'user',
            'personal',
            'Sprache',
            'select',
            'de',
            200,
            [
                'choices' => Locales::available(),
                'legacy'  => ['store' => 'user_preferences', 'column' => 'locale'],
            ],
        ));
        $settings->add(new SettingDefinition(
            'timezone',
            'user',
            'personal',
            'Zeitzone',
            'select',
            'Europe/Berlin',
            300,
            [
                'choices' => $zones,
                'legacy'  => ['store' => 'user_preferences', 'column' => 'timezone'],
            ],
        ));
        $settings->add(new SettingDefinition(
            'notify_in_app',
            'user',
            'personal',
            'Benachrichtigungen in Nafinity',
            'boolean',
            true,
            400,
            ['legacy' => ['store' => 'user_preferences', 'column' => 'notify_in_app']],
        ));
        $settings->add(new SettingDefinition(
            'notify_mail',
            'user',
            'personal',
            'Zusätzlich per E-Mail, wenn für den Workspace aktiviert',
            'boolean',
            false,
            500,
            ['legacy' => ['store' => 'user_preferences', 'column' => 'notify_mail']],
        ));

        $settings->add(new SettingDefinition(
            'muted',
            'project_user',
            'project_personal',
            'Benachrichtigungen dieses Projekts stummschalten',
            'boolean',
            false,
            100,
            ['legacy' => ['store' => 'project_preferences', 'column' => 'muted']],
        ));
    }

    private function project(ExtensionContext $context): void
    {
        $settings = $context->settings();
        $scales   = [];

        foreach ($context->estimationScales()->all() as $id => $scale) {
            $scales[$id] = $scale->label;
        }

        foreach (
            [
                ['name', 'Name', 'text', '', ['max' => 120]],
                ['description', 'Beschreibung', 'textarea', '', ['max' => 2000]],
                ['color', 'Farbe', 'text', '#6366f1', ['max' => 7]],
                ['icon', 'Symbol', 'text', 'N', ['max' => 2]],
                ['ticket_key', 'Ticketkürzel', 'text', '', ['max' => 6]],
                [
                    'estimation_scale',
                    'Schätzung',
                    'select',
                    Estimation::scale(null),
                    ['choices' => $scales ?: Estimation::LABELS],
                ],
            ] as $index => [$key, $label, $type, $default, $options]
        ) {
            $settings->add(new SettingDefinition(
                $key,
                'project',
                'general',
                $label,
                $type,
                $default,
                ($index + 1) * 100,
                [...$options, 'legacy' => ['store' => 'projects', 'column' => $key]],
                null,
                'manage',
            ));
        }
    }

    private function application(ExtensionContext $context): void
    {
        $settings = $context->settings();

        // Declared server configuration, readable by trusted code and by the CLI.
        // It has no card and no HTTP route: there is no global administrator role
        // to write it, and inventing one here would be inventing a right.
        $context->settingSections()->add(new SettingSection(
            'application',
            'application',
            'Allgemein',
            null,
            null,
            100,
        ));

        /*
         * The role editor the rbac package ships, as a card like any other.
         * Its id is not "roles" because the project card already has that one
         * and sections are keyed by id -- the second would replace the first.
         */
        $context->settingSections()->add(new SettingSection(
            'installation_users',
            'application',
            'Nutzer',
            'settings/users',
            UsersSectionProvider::class,
            150,
            null,
            'group',
        ));

        $context->settingSections()->add(new SettingSection(
            'installation_roles',
            'application',
            'Rollen & Rechte',
            'rbac/roles',
            null,
            200,
            null,
            'shield',
        ));

        $settings->add(new SettingDefinition(
            'mail_enabled',
            'application',
            'application',
            'Mailversand aktiviert',
            'boolean',
            false,
            100,
            [],
            null,
            null,
            false,
            'nafinity:mail_enabled',
        ));
        $settings->add(new SettingDefinition(
            'mail_from',
            'application',
            'application',
            'Absenderadresse',
            'text',
            '',
            200,
            ['nullable' => true],
            null,
            null,
            false,
            'nafinity:mail_from',
        ));
    }
}
