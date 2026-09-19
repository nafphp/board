<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

use function Naf\View\s;

/** @internal */
final class RichText
{
    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig())->allowLinkSchemes(['https', 'http', 'mailto'])->allowRelativeLinks();
        foreach (['p', 'br', 'h1', 'h2', 'h3', 'strong', 'em', 'u', 's', 'blockquote', 'pre', 'code', 'ol', 'ul', 'li'] as $tag) {
            $config = $config->allowElement($tag, []);
        }
        $config = $config->allowElement('a', ['href', 'title'])->withMaxInputLength(400000);

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    public static function plain(string $html): string
    {
        $separated = str_replace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '<br>', '<br />', '</blockquote>', '</pre>'], "\n", $html);

        return trim(html_entity_decode(strip_tags($separated), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function description(array $ticket): string
    {
        return $ticket['description_html'] !== null
            ? self::clean($ticket['description_html'])
            : nl2br(s($ticket['description']));
    }
}
