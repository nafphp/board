<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Naf\Board\Support\Fields\BooleanType;
use PHPUnit\Framework\TestCase;

use function Naf\Board\choice;
use function Naf\Board\field;
use function Naf\Board\partial;

final class SwitchFieldTest extends TestCase
{
    public function testSwitchKeepsItsNativeValueLabelHintAndDisabledState(): void
    {
        $xpath = $this->xpath(field([
            'label'    => 'Test <switch>',
            'name'     => 'enabled',
            'type'     => 'switch',
            'value'    => true,
            'hint'     => 'Test <hint>',
            'disabled' => true,
        ]));
        $input = $xpath->query('//input[@role="switch"]')->item(0);

        $this->assertNotNull($input);
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertSame('enabled', $input->getAttribute('name'));
        $this->assertSame('1', $input->getAttribute('value'));
        $this->assertTrue($input->hasAttribute('checked'));
        $this->assertTrue($input->hasAttribute('disabled'));
        $this->assertSame('field-enabled', $xpath->query('//label')->item(0)->getAttribute('for'));
        $this->assertSame('Test <switch>', $xpath->query('//label/span')->item(0)->textContent);
        $hintId = $input->getAttribute('aria-describedby');
        $this->assertSame('Test <hint>', $xpath->query('//*[@id="' . $hintId . '"]')->item(0)->textContent);
    }

    public function testBooleanSwitchSubmitsExplicitFalseWhenOffAndOneWhenOn(): void
    {
        $type = new BooleanType();
        foreach ([false, true] as $checked) {
            $xpath = $this->xpath(partial($type->view(), [
                'name'     => 'values[enabled]',
                'id'       => 'enabled',
                'value'    => $checked,
                'disabled' => false,
            ]));
            $this->assertCount(1, $xpath->query('//input[@role="switch"]'));

            // Native forms omit unchecked checkboxes and keep the last value of a name.
            $pairs = [];
            foreach ($xpath->query('//input') as $input) {
                if ($input->getAttribute('type') === 'checkbox' && !$input->hasAttribute('checked')) {
                    continue;
                }
                $pairs[] = urlencode($input->getAttribute('name')) . '=' . urlencode($input->getAttribute('value'));
            }
            parse_str(implode('&', $pairs), $submitted);
            $this->assertSame($checked ? '1' : '0', $submitted['values']['enabled']);
            $this->assertSame($checked, $type->normalize($submitted['values']['enabled'], []));
        }
    }

    public function testPlainCheckboxAndMultipleChoiceRemainSelectionControls(): void
    {
        $checkbox = $this->xpath(field(['label' => 'Selection', 'name' => 'selection', 'type' => 'checkbox']));
        $this->assertCount(1, $checkbox->query('//input[@type="checkbox" and not(@role)]'));

        $choices = $this->xpath(choice([
            'label'    => 'People',
            'name'     => 'people',
            'multiple' => true,
            'value'    => ['2'],
            'options'  => ['1' => 'Alice', '2' => 'Bob'],
        ]));
        $this->assertCount(2, $choices->query('//input[@type="checkbox" and not(@role)]'));
        $this->assertCount(1, $choices->query('//input[@name="people[]" and @value="2" and @checked]'));
        $this->assertCount(0, $choices->query('//*[@role="switch"]'));
    }

    private function xpath(string $markup): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($document);
    }
}
