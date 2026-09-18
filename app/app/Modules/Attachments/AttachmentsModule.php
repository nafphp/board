<?php

declare(strict_types=1);

namespace App\Modules\Attachments;

use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\AssetDefinition;
use Nafinity\Definition\UiContribution;
use Nafinity\ExtensionContext;
use Nafinity\Support\UiContext;

/**
 * Uploads as a module, registered the same way a foreign plugin would be.
 *
 * It ships with the application rather than as a separate package, but it uses
 * nothing the registry does not offer to everyone: a widget, a template and its
 * browser asset. Removing the widget removes the surface, not the files, the
 * routes or anyone's permission — those are a separate decision.
 *
 * The existing AttachmentService, private storage, quotas, the staged/ready
 * lifecycle and the finalizing job are unchanged.
 */
final class AttachmentsModule implements ExtensionProviderInterface
{
    public const string WIDGET_ID = 'core.ticket.attachments';

    public function register(ExtensionContext $context): void
    {
        $context->ui()->add(new UiContribution(
            self::WIDGET_ID,
            'ticket.main.widgets',
            'ticket/widgets/attachments',
            200,
            null,
            // Reading the list is reading the project. Uploading and deleting
            // still ask for `upload` inside the widget and again in the service.
            'read',
            [UiContext::MODE_DETAIL],
        ));

        // The upload behaviour is an ordinary script, not a mountable module, so
        // it is registered as this module's asset rather than as the widget's
        // browser module. Removing the widget takes the asset with it.
        $context->assets()->add(new AssetDefinition(
            'core.upload',
            '/assets/upload.js',
            'js',
            200,
            true,
        ));
    }
}
