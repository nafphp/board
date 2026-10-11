<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Naf\Board\Domain\Failure;
use Naf\Board\Mail\Design;
use Naf\Board\Mail\Messages;
use Naf\Board\Support\Branding;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Naf\app;

final class EmailDesignTest extends TestCase
{
    private Config $original;

    protected function setUp(): void
    {
        $this->original = app()->container()->get(Config::class);
        app()->container()->set(Config::class, new Config([
            'app'      => ['name' => 'Studio <North> & Team', 'url' => 'https://work.example.test'],
            'mail'     => ['from' => 'support@example.test'],
            'nafinity' => ['branding' => ['logo' => str_repeat('a', 64) . '.png']],
        ]));
    }

    protected function tearDown(): void
    {
        app()->container()->set(Config::class, $this->original);
    }

    public function testEveryMailTypeUsesTheGlobalIdentityAndSameInlineDesign(): void
    {
        foreach (array_keys(Design::SAMPLES) as $type) {
            $html = Design::sample($type);
            self::assertStringContainsString('Studio &lt;North&gt; &amp; Team', $html);
            self::assertStringNotContainsString('Nafinity', $html);
            self::assertStringContainsString('https://work.example.test/branding/logo/' . str_repeat('a', 64) . '.png', $html);
            self::assertStringNotContainsString('var(--', $html);
            self::assertStringNotContainsString('<style', $html);
            self::assertStringContainsString('background: #6558e8', $this->style($html, 'email-header'));
        }
        self::assertSame('Studio <North> & Team · Einladung', Branding::subject('Einladung'));
    }

    public function testCustomCssOverridesTokensAndIsInlinedInPreviewAndSentMail(): void
    {
        $design                               = Design::DEFAULTS;
        $design['colors']['accent']           = '#112233';
        $design['common']['font-family']      = 'serif';
        $design['common']['density']          = '1.25';
        $design['css']                        = '.email-title { color: var(--accent); padding-bottom: var(--space-16); }';
        $settings                             = app()->container()->get(Config::class)->all();
        $settings['nafinity']['email_design'] = $design;
        app()->container()->set(Config::class, new Config($settings));
        $html  = Design::sample('invitation');
        $style = $this->style($html, 'email-title');
        self::assertStringContainsString('color: #112233', $style);
        self::assertStringContainsString('padding-bottom: 20px', $style);
        self::assertStringContainsString('Georgia', $this->style($html, null));
        $mail = Messages::create(new Mailer(new DummyTransport()), 'invitation', ['projectName' => 'Example', 'url' => 'https://example.test/preview']);
        self::assertSame($style, $this->style($mail->getContent(), 'email-title'));
        self::assertStringContainsString('Studio <North> & Team', $mail->getTextContent());
        self::assertStringContainsString('https://example.test/preview', $mail->getTextContent());
        self::assertStringNotContainsString('<table', $mail->getTextContent());
    }

    #[DataProvider('invalidDesigns')]
    public function testInvalidDesignsAreRejected(array $changes): void
    {
        $this->expectException(Failure::class);
        Design::normalize(array_replace_recursive(Design::DEFAULTS, $changes));
    }

    public static function invalidDesigns(): array
    {
        return [
            [['css' => '@import "https://example.test/a.css";']],
            [['css' => '.email-title { background: url(https://example.test/pixel); }']],
            [['css' => '.email-title { color: var(--unknown); }']],
            [['css' => str_repeat(' ', 32769)]],
            [['common' => ['density' => '3']]], [['common'               => ['font-family' => 'url(evil)']]],
            [['colors' => ['accent' => 'red;display:none']]], [['colors' => ['unknown' => '#112233']]],
        ];
    }

    public function testMissingLogoAndInvalidPublicUrlFallBackWithoutLeakingAPrivatePath(): void
    {
        $settings                = app()->container()->get(Config::class)->all();
        $settings['app']['url']  = 'javascript:alert(1)';
        $settings['app']['name'] = "Studio\r\nInjected header";
        app()->container()->set(Config::class, new Config($settings));
        self::assertNull(Branding::logoUrl(true));
        self::assertSame('Studio Injected header · Test', Branding::subject('Test'));
        self::assertStringNotContainsString('class="email-logo"', Design::sample('invitation'));
    }

    private function style(string $html, ?string $class): string
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $query = $class === null ? '//body' : '//*[@class="' . $class . '"]';

        return (new DOMXPath($document))->query($query)->item(0)->getAttribute('style');
    }
}
