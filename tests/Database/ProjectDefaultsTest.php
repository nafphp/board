<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * What a project is given when the person creating it leaves a field alone.
 *
 * Both fields on the form say "Aus dem Namen" and neither did it: the key was
 * refused as missing, and the mark was whatever the form happened to have in it,
 * which was the letter N for every project anybody ever made.
 */
final class ProjectDefaultsTest extends BoardTestCase
{
    public function testAKeyIsTakenFromTheNameWhenNobodyTypesOne(): void
    {
        $project = $this->projects->create(['name' => 'Faulancer', 'description' => '']);

        $this->assertSame('FLNCR', $this->keyOf($project));
    }

    public function testAKeyThatWasTypedIsKept(): void
    {
        $project = $this->projects->create([
            'name'        => 'Faulancer',
            'description' => '',
            'ticket_key'  => 'flan',
        ]);

        $this->assertSame('FLAN', $this->keyOf($project), 'it is upper-cased, not replaced');
    }

    public function testTheMarkIsTheFirstLetterRatherThanWhateverTheFormHeld(): void
    {
        $project = $this->projects->create(['name' => 'Studio Nord', 'description' => '']);

        $this->assertSame('S', $this->scalar('SELECT icon FROM projects WHERE id=?', [$project]));
    }

    /** A name with nothing to abbreviate still needs something in front of its numbers. */
    public function testANameWithoutLettersStillGetsAKey(): void
    {
        $project = $this->projects->create(['name' => '#!?', 'description' => '']);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,6}$/', $this->keyOf($project));
    }

    private function keyOf(int $project): string
    {
        return (string) $this->scalar('SELECT ticket_key FROM projects WHERE id=?', [$project]);
    }
}
