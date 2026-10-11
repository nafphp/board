<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use Naf\Mail\Core\Mailer;
use Naf\Mail\Models\Mail;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

use function Naf\config;
use function Naf\View\view;

/** Shared sender and views for every kind of Board email. */
final class Messages
{
    public static function enabled(): bool
    {
        return filter_var(config('mail:notifications_enabled', config('nafinity:mail_enabled', false)), FILTER_VALIDATE_BOOL);
    }

    public static function create(Mailer $mailer, string $template, array $data = []): Mail
    {
        $mail = $mailer->createMail();
        if ($mail->getFrom() === '') {
            // Existing installations keep working until their configuration is consolidated.
            $mail->setFrom((string) config('nafinity:mail_from', ''));
        }
        $html = self::html($template, $data);

        return $mail->setContent($html)->setTextContent(self::plainText($html));
    }

    public static function html(string $template, array $data = [], ?array $design = null): string
    {
        $html = view('mail/' . $template, $data);
        // Owner-provided native view overrides may deliberately use their own layout.
        if (!str_contains($html, 'data-email-layout')) {
            return $html;
        }
        $design = Design::normalize($design ?? Design::current());

        return (new CssToInlineStyles())->convert($html, Design::css($design));
    }

    private static function plainText(string $html): string
    {
        $html  = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html  = preg_replace_callback('/<a\b[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', static fn(array $link) => $link[2] . ' (' . $link[1] . ')', $html);
        $html  = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|tr|h[1-6])>/i', "\n", $html);
        $text  = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map('trim', explode("\n", $text));

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    }
}
