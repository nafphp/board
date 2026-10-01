<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

use function Naf\I18n\t;
use function Naf\request;
use function Naf\route;

/**
 * The files on a ticket: adding one, removing one, handing one out.
 *
 * @internal
 */
final class AttachmentController
{
    public function __construct(
        private AttachmentServiceInterface $attachments,
        private TicketServiceInterface $tickets,
        private AccessInterface $access,
    ) {
    }

    public function upload(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function () use ($project, $ticket) {
            $file = request()->getUploadedFiles()['attachment'] ?? null;
            if (!($file instanceof UploadedFileInterface)) {
                throw new Failure(t('Bitte wähle eine Datei.'));
            }
            $projectId = Input::id($project);
            $this->attachments->upload($projectId, $this->tickets->resolve($projectId, $ticket), $file);

            return ['url' => route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function delete(string $project, string $ticket, string $attachment): ResponseInterface
    {
        return Respond::mutation($this->access, function () use ($project, $ticket, $attachment) {
            $projectId = Input::id($project);
            $this->attachments->remove(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                Input::id($attachment),
            );

            return ['url' => route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    /**
     * Always as a download, under a fixed ASCII name with the real one beside it,
     * so nothing a person uploaded is ever rendered by the browser.
     */
    public function download(string $project, string $ticket, string $attachment): ResponseInterface
    {
        return Respond::read(function () use ($project, $ticket, $attachment) {
            $projectId = Input::id($project);
            $file      = $this->attachments->download(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                Input::id($attachment),
            );
            $disposition = "attachment; filename=\"download\"; filename*=UTF-8''"
                . rawurlencode($file['original_name']);

            return Respond::download($file['stream'], 'application/octet-stream', $disposition)
                ->withHeader('Content-Length', (string) $file['byte_size']);
        });
    }
}
