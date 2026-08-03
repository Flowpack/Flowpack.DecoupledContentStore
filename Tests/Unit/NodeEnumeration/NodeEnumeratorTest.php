<?php

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration;

use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use PHPUnit\Framework\TestCase;

class NodeEnumeratorTest extends TestCase
{

    private static function buildNodeTypeFilter(string $nodeTypeWhitelist): string
    {
        $method = new \ReflectionMethod(NodeEnumerator::class, 'buildNodeTypeFilter');
        return $method->invoke(null, $nodeTypeWhitelist);
    }

    public function testSingleNodeTypeProducesSimpleInstanceofFilter(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document]',
            self::buildNodeTypeFilter('Neos.Neos:Document')
        );
    }

    public function testNegatedNodeTypesAreCombinedWithAndInsteadOfOr(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter('Neos.Neos:Document,!Neos.Neos:Shortcut')
        );
    }

    public function testFilterPartsAreNotJoinedByComma(): void
    {
        // FlowQuery parses comma-separated filter groups independently, so a group
        // consisting only of "[!instanceof ...]" makes find() throw
        // "find() needs an identifier, path or instanceof filter for the first filter part".
        $filter = self::buildNodeTypeFilter(
            'Neos.Neos:Document'
            . ',!Neos.Neos:Shortcut'
            . ',!Louis.Site:Document.HomePage.Settings'
            . ',!Louis.Site:Document.HomePage.NodeCatalogue'
            . ',!Louis.Site:Document.ErrorPage'
        );

        self::assertStringNotContainsString(',', $filter);
        self::assertSame(
            '[instanceof Neos.Neos:Document]'
            . '[!instanceof Neos.Neos:Shortcut]'
            . '[!instanceof Louis.Site:Document.HomePage.Settings]'
            . '[!instanceof Louis.Site:Document.HomePage.NodeCatalogue]'
            . '[!instanceof Louis.Site:Document.ErrorPage]',
            $filter
        );
    }

    public function testPositiveFiltersAreOrderedFirstEvenIfConfiguredAfterExclusions(): void
    {
        // find() also throws if the combined filter STARTS with "[!instanceof ...]",
        // so positive filters must be ordered first regardless of the configured order.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter('!Neos.Neos:Shortcut,Neos.Neos:Document')
        );
    }

}
