<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Branding;
use Naf\Board\Support\BrandLogoStorage;
use Naf\Board\Support\Settings\FileConfigurationStore;
use Naf\Core\Config;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function Naf\I18n\t;
use function Naf\log;

/** Branding uses the same authorization, typed configuration and revision as other settings. @internal */
final class BrandingSettings
{
    public function __construct(
        private ApplicationSettings $settings,
        private BoardQueryInterface $query,
        private BrandLogoStorage $logos,
        private Config $configuration,
        private FileConfigurationStore $store,
    ) {
    }

    public function save(array $data, ?UploadedFileInterface $upload): void
    {
        $this->settings->authorize();
        $revision = $data['configurationRevision'] ?? null;
        if (!is_string($revision)) {
            throw new Failure(t('Bitte lade die Einstellungen neu.'), 409);
        }
        $previous   = Branding::logoKey();
        $serverLogo = (new Config($this->configuration->base()))->get(Branding::LOGO_PATH);
        if (($data['reset'] ?? '') === '1') {
            $this->settings->save([], [Branding::NAME, Branding::HOME, Branding::LOGO, Branding::COLOR, Branding::FONT], $revision);
        } else {
            $home      = $data['home_project'] ?? '0';
            $available = ['0'];
            foreach ($this->query->projects() as $project) {
                if (empty($project['archived_at'])) {
                    $available[] = (string) $project['id'];
                }
            }
            if ((!is_string($home) && !is_int($home)) || !in_array((string) $home, $available, true)) {
                throw new Failure(t('Bitte wähle die Projektübersicht oder ein verfügbares Board.'), 422);
            }
            $values = [Branding::NAME => $data['name'] ?? null, Branding::HOME => $home];
            if (array_key_exists('color_mode', $data)) {
                if (!in_array($data['color_mode'], ['auto', 'custom'], true)) {
                    throw new Failure(t('Bitte wähle eine gültige Schriftfarbe.'), 422);
                }
                $values[Branding::COLOR] = $data['color_mode'] === 'auto' ? null : ($data['color'] ?? '');
            }
            if (array_key_exists('font', $data)) {
                $values[Branding::FONT] = $data['font'];
            }
            $remove    = ($data['remove_logo'] ?? '') === '1';
            $hasUpload = $upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE;
            if ($remove && $hasUpload) {
                throw new Failure(t('Bitte wähle entweder ein neues Logo oder „Logo entfernen“.'), 422);
            }
            $created = null;

            try {
                if ($hasUpload) {
                    $created                = $this->logos->upload($upload);
                    $values[Branding::LOGO] = $created;
                } elseif ($remove) {
                    $values[Branding::LOGO] = null;
                }
                $this->settings->save($values, [], $revision);
            } catch (Throwable $exception) {
                if ($created !== null) {
                    $this->logos->delete($created);
                }
                throw $exception;
            }
        }
        if ($previous !== null && $previous !== $serverLogo && $previous !== Branding::logoKey()) {
            try {
                // Serialize cleanup with every configuration write. Another admin may
                // have selected the previous key again after this form was saved.
                $this->store->update(function (array $current) use ($previous, $serverLogo): array {
                    $configured = array_key_exists(Branding::LOGO_PATH, $current)
                        ? $current[Branding::LOGO_PATH] : $serverLogo;
                    if ($configured !== $previous) {
                        $this->logos->delete($previous);
                    }

                    return $current;
                });
            } catch (Throwable) {
                // Saving succeeded; a storage cleanup failure must not report a failed save.
                log()->warning('Branding saved, but the previous logo could not be removed.');
            }
        }
    }
}
