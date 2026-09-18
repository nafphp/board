<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use LogicException;
use Nafinity\Definition\ViewOverride;

/**
 * Explicit mappings from a logical view name to another template.
 *
 * This does not change how NAF searches view directories: the host's app/views
 * still wins over plugin view paths. A mapping simply names a different target.
 */
final class ViewRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(ViewOverride::class, 'View override');
    }

    public function get(string $id): ?ViewOverride
    {
        return parent::get(self::logical($id));
    }

    public function remove(string $id): bool
    {
        return parent::remove(self::logical($id));
    }

    /**
     * NAF accepts `ticket.field` and `ticket/field` for the same template, so a
     * mapping means the same thing however the calling template spells it.
     *
     * @param string $template Logical view name in either spelling
     */
    public static function logical(string $template): string
    {
        return str_replace('.', '/', $template);
    }

    protected function identify(object $definition): string
    {
        return self::logical($definition->id);
    }

    /**
     * Resolve a logical view name to the template that should render it
     *
     * @param string $template Logical view name
     *
     * @return string The mapped template name
     */
    public function resolve(string $template): string
    {
        $template = self::logical($template);
        $seen     = [$template => true];
        $current  = $template;

        while (null !== $override = $this->get($current)) {
            $next = self::logical($override->template);

            if ($next === $current) {
                return $next;
            }

            if (isset($seen[$next])) {
                throw new LogicException(sprintf(
                    'View mapping cycle for "%s": %s.',
                    $template,
                    implode(' -> ', [...array_keys($seen), $next]),
                ));
            }

            $seen[$next] = true;
            $current     = $next;
        }

        return $current;
    }
}
