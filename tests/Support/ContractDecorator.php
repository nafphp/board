<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use ReflectionClass;

/**
 * A stand-in for any contract, generated from the contract itself.
 *
 * Replacing a service is what an extension does, so the question is whether the
 * consumers really receive the replacement -- and a stand-in has to satisfy
 * their type hints to find out. Writing one by hand for each of thirteen
 * interfaces would be thirteen chances to write a different one; generating it
 * from the interface keeps them the same and keeps them current.
 *
 * It forwards everything and adds nothing but a record of what was called,
 * which is exactly what a decorator an extension writes is free to be.
 */
final class ContractDecorator
{
    /** @var array<class-string,class-string> */
    private static array $built = [];

    /**
     * @param class-string $contract
     * @return class-string the generated decorator, taking the original in its constructor
     */
    public static function for(string $contract): string
    {
        if (isset(self::$built[$contract])) {
            return self::$built[$contract];
        }

        $name    = 'Decorated' . str_replace('\\', '', $contract);
        $methods = '';

        foreach ((new ReflectionClass($contract))->getMethods() as $method) {
            $parameters = [];
            $arguments  = [];

            foreach ($method->getParameters() as $parameter) {
                $type     = $parameter->getType();
                $declared = $type === null ? '' : ltrim((string) $type, '?');
                // `mixed` already includes null; marking it nullable is a parse error.
                $nullable = $type !== null
                    && $type->allowsNull()
                    && !str_contains($declared, 'null')
                    && $declared !== 'mixed';
                $piece = ($type === null ? '' : ($nullable ? '?' : '') . $declared . ' ')
                    . '$' . $parameter->getName();

                if ($parameter->isDefaultValueAvailable()) {
                    $piece .= ' = ' . var_export($parameter->getDefaultValue(), true);
                }

                $parameters[] = $piece;
                $arguments[]  = '$' . $parameter->getName();
            }

            $returnType = $method->getReturnType();
            $call       = '$this->inner->' . $method->getName() . '(' . implode(', ', $arguments) . ')';

            $methods .= sprintf(
                "    public function %s(%s)%s { \$this->calls[] = '%s'; %s }\n",
                $method->getName(),
                implode(', ', $parameters),
                $returnType === null ? '' : ': ' . (string) $returnType,
                $method->getName(),
                (string) $returnType === 'void' ? $call . ';' : 'return ' . $call . ';',
            );
        }

        eval(sprintf(
            'final class %s implements %s { public array $calls = []; '
            . 'public function __construct(private %s $inner) {} %s }',
            $name,
            $contract,
            $contract,
            $methods,
        ));

        return self::$built[$contract] = $name;
    }
}
