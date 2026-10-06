<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Board\Services\AuditLog;
use Psr\Http\Message\ResponseInterface;

use function Naf\I18n\t;
use function Naf\Rbac\rbac;
use function Naf\request;
use function Naf\route;

/**
 * What belongs to the installation rather than to any project: its settings,
 * its accounts and its history.
 *
 * The permissions are asked of naf/rbac directly, because the board's own scope
 * object answers for a project and these pages belong to none.
 *
 * @internal
 */
final class InstallationController
{
    public function __construct(
        private AccessInterface $access,
        private AccountServiceInterface $accounts,
        private AuditLog $audit,
        private PageRendererInterface $pages,
    ) {
    }

    /**
     * What holds for the whole installation, for whoever may change it.
     *
     * Gated here rather than by hiding the cards: a page that refuses is a page
     * somebody can be sent a link to and understand.
     */
    public function settings(): ResponseInterface
    {
        return Respond::read(function () {
            if (!rbac()->allows($this->access->actor(), Installation::MANAGE_SETTINGS)) {
                throw new Failure(t('Diese Seite ist Administratoren vorbehalten.'), 403);
            }

            return $this->pages->render('settings', [
                'title'   => 'Installation',
                'eyebrow' => 'DIESE INSTALLATION',
                'heading' => 'Installation',
                'lede'    => 'Gilt für alle Projekte und alle Mitglieder.',
                'scopes'  => ['application'],
                'return'  => '/settings',
            ]);
        });
    }

    /** Open an account for somebody else; the service decides whether you may. */
    public function createAccount(): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) {
            $this->accounts->create($data);

            return ['url' => route('installation.settings')];
        });
    }

    /**
     * The installation's own history.
     *
     * Its own page and its own right: what a board records is part of that
     * board, but who changed a role, who was given an account and which switch
     * was flipped belongs to nobody's board and has to be readable somewhere.
     */
    public function audit(): ResponseInterface
    {
        return Respond::read(function () {
            $actor = $this->access->actor();
            if (!rbac()->allows($actor, Installation::VIEW_AUDIT)) {
                throw new Failure(t('Für das Protokoll fehlt dir die Berechtigung.'), 403);
            }

            $query  = request()->getQueryParams();
            $filter = [];
            // An empty scope means the installation and is a real choice, so it
            // is told apart from "no filter" by whether the parameter is there.
            if (isset($query['scope']) && $query['scope'] !== 'alle') {
                $filter['scope'] = (string) $query['scope'];
            }
            foreach (['actor', 'type', 'q'] as $key) {
                if (!empty($query[$key])) {
                    $filter[$key] = $query[$key];
                }
            }

            $before = (int) ($query['before'] ?? 0);

            // Reading the log and reading what happened to accounts are two
            // questions, so they are asked separately.
            $personal = rbac()->allows($actor, Installation::VIEW_PERSONAL_AUDIT);

            return $this->pages->render('audit', [
                'title'   => 'Protokoll',
                'entries' => $this->audit->entries($filter, $before, 100, $personal),
                'scopes'  => $this->audit->scopes(),
                'actors'  => $this->audit->actors(),
                'types'   => $this->audit->types($personal),
                'filter'  => $filter,
                'chosen'  => [
                    'scope' => $query['scope'] ?? 'alle',
                    'actor' => (string) ($query['actor'] ?? ''),
                    'type'  => (string) ($query['type'] ?? ''),
                    'q'     => (string) ($query['q'] ?? ''),
                ],
            ]);
        });
    }
}
