<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Services\InvoiceConnections;
use Naf\Board\Services\InvoiceDraftService;
use Naf\Board\Services\TimeExportService;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\extensions;
use function Naf\request;
use function Naf\route;

final class InvoiceDraftController
{
    public function __construct(
        private AccessInterface $access,
        private PageRendererInterface $pages,
        private InvoiceConnections $connections,
        private InvoiceDraftService $drafts,
        private TimeExportService $time,
    ) {
    }

    public function show(): ResponseInterface
    {
        return Respond::read(function () {
            $this->access->actor();
            $input    = request()->getQueryParams();
            $selected = isset($input['connection']) ? $this->connections->get(Input::id($input['connection'], 'connection')) : null;
            if ($selected !== null) {
                unset($selected['credentials']);
            }

            return $this->pages->render('invoice-drafts', [
                'title'           => 'Rechnungsentwürfe',
                'connections'     => $this->connections->available(),
                'companyAllowed'  => $this->connections->companyAllowed(),
                'encryptionReady' => $this->connections->ready(),
                'formats'         => extensions()->exporters()->forDrafts(),
                'invoiceProjects' => $this->time->availableProjects($selected !== null && (int) $selected['owner_id'] === 0),
                'selected'        => $selected,
                'extraFields'     => $selected === null ? [] : $this->connections->adapter($selected['provider'])->fields(),
                'draft'           => isset($input['draft']) ? $this->drafts->get(is_string($input['draft']) ? $input['draft'] : '') : null,
                'history'         => $this->drafts->history(),
            ]);
        })->withHeader('Cache-Control', 'private, no-store');
    }

    public function connect(): ResponseInterface
    {
        return $this->mutate(fn(array $input): array => ['connection' => $this->connections->save($input)]);
    }

    public function disconnect(): ResponseInterface
    {
        return $this->mutate(function (array $input): array {
            $this->connections->revoke(Input::id($input['connection'] ?? null, 'connection'));

            return [];
        });
    }

    public function preview(): ResponseInterface
    {
        return $this->mutate(fn(array $input): array => ['draft' => $this->drafts->prepare($input)]);
    }

    public function send(): ResponseInterface
    {
        return $this->mutate(function (array $input): array {
            $id = is_string($input['draft'] ?? null) ? $input['draft'] : '';
            $this->drafts->send($id);

            return ['draft' => $id];
        });
    }

    public function resolve(): ResponseInterface
    {
        return $this->mutate(function (array $input): array {
            $id = is_string($input['draft'] ?? null) ? $input['draft'] : '';
            $this->drafts->resolve($id, $input);

            return ['draft' => $id];
        });
    }

    private function mutate(callable $operation): ResponseInterface
    {
        return Respond::read(fn() => Respond::mutation($this->access, function (array $input) use ($operation): array {
            $query = $operation($input);

            return ['url' => route('invoice.drafts') . ($query === [] ? '' : '?' . http_build_query($query))];
        }))->withHeader('Cache-Control', 'private, no-store');
    }
}
