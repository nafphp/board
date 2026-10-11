<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

use function Naf\View\view;

final class ChoiceComponentTest extends TestCase
{
    public function testSharedMultiselectsKeepAllScopesAndHaveDistinctAccessibleIds(): void
    {
        $markup = '<input type="hidden" name="grants" value="">';
        foreach (['' => ['1|' => 'Global'], 'project:7' => ['2|project:7' => 'Member', '3|project:7' => 'Reviewer']] as $scope => $options) {
            $markup .= view('components/choice', [
                'name'       => 'grants', 'label' => 'Roles', 'multiple' => true,
                'id'         => 'roles-' . $scope, 'listId' => 'list-' . $scope,
                'clearField' => false, 'options' => $options, 'value' => array_keys($options),
            ]);
        }
        $xpath = $this->xpath($markup);
        $this->assertCount(1, $xpath->query('//input[@name="grants"]'));
        $fields = [];
        foreach ($xpath->query('//input[@name and (@type="hidden" or @checked)]') as $input) {
            $fields[] = rawurlencode($input->getAttribute('name')) . '=' . rawurlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $fields), $body);
        $this->assertSame(['1|', '2|project:7', '3|project:7'], $body['grants']);
        foreach ($xpath->query('//button[@data-choice-trigger]') as $trigger) {
            $id = $trigger->getAttribute('aria-controls');
            $this->assertCount(1, $xpath->query('//*[@id="' . $id . '" and @role="listbox"]'));
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $this->assertNotContains($node->getAttribute('id'), $ids);
            $ids[] = $node->getAttribute('id');
        }
    }

    public function testDefaultEmptyMultiselectStillSubmitsItsClearMarker(): void
    {
        $xpath = $this->xpath(view('components/choice', ['name' => 'roles', 'label' => 'Roles', 'multiple' => true]));
        $this->assertCount(1, $xpath->query('//input[@name="roles" and @type="hidden" and @value=""]'));
    }

    private function xpath(string $markup): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($document);
    }
}
