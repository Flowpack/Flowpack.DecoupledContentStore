<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration;

use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the FlowQuery filter built from the "nodeRendering.nodeTypeWhitelist" setting, which is a
 * list of node types where entries prefixed with "!" are excluded:
 *
 *     nodeTypeWhitelist:
 *       - 'Neos.Neos:Document'
 *       - '!My.Package:Bar'
 */
class NodeEnumeratorTest extends TestCase
{
    /**
     * @param array<int, string> $nodeTypeWhitelist the setting as it arrives from the YAML configuration
     */
    private static function buildNodeTypeFilter(array $nodeTypeWhitelist): string
    {
        $method = new \ReflectionMethod(NodeEnumerator::class, 'buildNodeTypeFilter');
        return $method->invoke(null, $nodeTypeWhitelist);
    }

    public function testSingleNodeTypeProducesSimpleInstanceofFilter(): void
    {
        self::assertSame('[instanceof Neos.Neos:Document]', self::buildNodeTypeFilter(['Neos.Neos:Document']));
    }

    public function testNegatedNodeTypesAreCombinedWithAndInsteadOfOr(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['Neos.Neos:Document', '!Neos.Neos:Shortcut'])
        );
    }

    public function testFilterPartsAreNotJoinedByComma(): void
    {
        // FlowQuery parses comma-separated filter groups independently, so a group
        // consisting only of "[!instanceof ...]" makes find() throw
        // "find() needs an identifier, path or instanceof filter for the first filter part".
        $filter = self::buildNodeTypeFilter([
            'Neos.Neos:Document',
            '!Neos.Neos:Shortcut',
            '!My.Package:Bar',
            '!My.Package:Baz'
        ]);

        self::assertStringNotContainsString(',', $filter);
        self::assertSame(
            '[instanceof Neos.Neos:Document]'
            . '[!instanceof Neos.Neos:Shortcut]'
            . '[!instanceof My.Package:Bar]'
            . '[!instanceof My.Package:Baz]',
            $filter
        );
    }

    public function testPositiveFiltersAreOrderedFirstEvenIfConfiguredAfterExclusions(): void
    {
        // find() also throws if the combined filter STARTS with "[!instanceof ...]",
        // so positive filters must be ordered first regardless of the configured order.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut', 'Neos.Neos:Document'])
        );
    }

    public function testSurroundingWhitespaceOfConfiguredEntriesIsIgnored(): void
    {
        // The "!" is detected after trimming, so a padded exclusion still excludes.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter([' Neos.Neos:Document ', "\t!Neos.Neos:Shortcut\n"])
        );
    }

    public function testEmptyEntriesAreSkipped(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document]',
            self::buildNodeTypeFilter(['Neos.Neos:Document', '', '   '])
        );
    }

    public function testExclusionOnlyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        // Without a positive filter, find() would throw exception 1436884196.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut'])
        );
    }

    public function testEmptyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        self::assertSame('[instanceof Neos.Neos:Document]', self::buildNodeTypeFilter([]));
    }
}
