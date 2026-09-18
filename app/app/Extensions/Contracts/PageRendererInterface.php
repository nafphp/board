<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Psr\Http\Message\ResponseInterface;

/**
 * Renders a normal Nafinity page with the application shell.
 *
 * The shared context — language, own projects, running timer, user and
 * preferences — comes from the renderer. Template data never overwrites it, so
 * a contributed page cannot quietly claim to belong to somebody else.
 */
interface PageRendererInterface
{
    /**
     * Render a full page, including the shell
     *
     * @param string $template Logical view name
     * @param array  $data     Template variables of the page itself
     */
    public function render(string $template, array $data = []): ResponseInterface;

    /**
     * Render a fragment: view mapping and partial resolution, no shell data
     *
     * @param string $template Logical view name
     * @param array  $data     Template variables
     */
    public function fragment(string $template, array $data = []): string;
}
