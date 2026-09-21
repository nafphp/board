<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Support\Locales;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\I18n\translation_paths;
use function Naf\I18n\translator;

/**
 * An extension may bring a language the application does not speak.
 *
 * Its directory counts the same as the application's own, so French becomes
 * selectable by installing a package -- and the picker offers it because a file
 * for it now exists, not because anyone listed it.
 */
final class TranslationContributionTest extends ExtensionInstalledTestCase
{
    public function testThePackagesTranslationDirectoryIsRegistered(): void
    {
        $this->assertArrayHasKey('example.reports', translation_paths()->all());
    }

    public function testALanguageAPackageBringsBecomesSelectable(): void
    {
        $this->assertArrayHasKey('fr', Locales::available());
    }

    public function testThePackagesWordingIsUsed(): void
    {
        $translator = translator();
        $before     = $translator->getLanguage();

        try {
            $translator->setLanguage('fr');

            $this->assertSame('Vérification', $translator->translate('Prüfung'));
        } finally {
            $translator->setLanguage((string) $before);
        }
    }
}
