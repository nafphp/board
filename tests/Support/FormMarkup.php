<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The forms and controls a page actually rendered, read as a document.
 *
 * Read with a parser rather than with patterns, because the questions here are
 * structural -- how many forms there are, whether one is inside another, which
 * controls belong to which -- and a regular expression cannot answer those. A
 * form nested inside another is silently dropped by every browser, so "exactly
 * one form, not nested" is a real thing to check.
 */
final class FormMarkup
{
    private DOMXPath $xpath;

    public function __construct(string $markup)
    {
        $document = new DOMDocument();
        // The fragments are HTML, and HTML is full of things libxml calls errors.
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $markup);
        libxml_clear_errors();

        $this->xpath = new DOMXPath($document);
    }

    /** @return list<DOMElement> */
    public function forms(string $withAttribute = ''): array
    {
        $query = $withAttribute === '' ? '//form' : '//form[@' . $withAttribute . ']';

        return iterator_to_array($this->xpath->query($query) ?: []);
    }

    /** Whether any form sits inside another, which no browser would submit. */
    public function hasNestedForm(): bool
    {
        return ($this->xpath->query('//form//form')?->length ?? 0) > 0;
    }

    /**
     * Every named control on the page, by name.
     *
     * @return array<string,DOMElement>
     */
    public function controls(): array
    {
        $found = [];
        foreach ($this->xpath->query('//input[@name]|//select[@name]|//textarea[@name]') ?: [] as $control) {
            $found[$control->getAttribute('name')] = $control;
        }

        return $found;
    }

    public function control(string $name): ?DOMElement
    {
        return $this->controls()[$name] ?? null;
    }

    public function valueOf(string $name): string
    {
        return $this->control($name)?->getAttribute('value') ?? '';
    }
}
