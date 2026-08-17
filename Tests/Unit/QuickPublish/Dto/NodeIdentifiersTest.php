<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\QuickPublish\Dto;

use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests the list of node identifiers a quick content release is asked to publish.
 *
 * The list is typed into a text field and ends up inside a prunner shell command, so everything which is not an
 * identifier has to be refused here rather than somewhere down the pipeline.
 */
final class NodeIdentifiersTest extends UnitTestCase
{
    private const IDENTIFIER = '3239baee-3e7f-785c-0853-f4302ef32570';
    private const OTHER_IDENTIFIER = 'de3b7ec6-b9d1-40d1-91ae-a9c1a4d47dab';

    public function testIdentifiersAreReadOnePerEntry(): void
    {
        $nodeIdentifiers = NodeIdentifiers::fromCommaSeparatedString(
            self::IDENTIFIER . ',' . self::OTHER_IDENTIFIER
        );

        self::assertSame([self::IDENTIFIER, self::OTHER_IDENTIFIER], $nodeIdentifiers->jsonSerialize());
    }

    public function testSurroundingWhitespaceAndEmptyEntriesAreIgnored(): void
    {
        // the identifiers arrive from a textarea, one per line
        $nodeIdentifiers = NodeIdentifiers::fromCommaSeparatedString(
            " " . self::IDENTIFIER . " ,\n,\t" . self::OTHER_IDENTIFIER . ","
        );

        self::assertSame([self::IDENTIFIER, self::OTHER_IDENTIFIER], $nodeIdentifiers->jsonSerialize());
    }

    public function testUppercaseIdentifiersAreAccepted(): void
    {
        $identifier = strtoupper(self::IDENTIFIER);

        self::assertSame([$identifier], NodeIdentifiers::fromCommaSeparatedString($identifier)->jsonSerialize());
    }

    public function testTheSameIdentifierIsPublishedOnlyOnce(): void
    {
        $nodeIdentifiers = NodeIdentifiers::fromCommaSeparatedString(self::IDENTIFIER . ',' . self::IDENTIFIER);

        self::assertSame(1, $nodeIdentifiers->count());
    }

    /**
     * @dataProvider notAnIdentifier
     */
    public function testAnythingWhichIsNotAnIdentifierIsRefused(string $nodeIdentifiers): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786958510);

        NodeIdentifiers::fromCommaSeparatedString($nodeIdentifiers);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function notAnIdentifier(): array
    {
        return [
            'a shell command' => [self::IDENTIFIER . '; rm -rf /'],
            'a node path' => ['/sites/test/products'],
            'too short' => ['3239baee-3e7f-785c-0853-f4302ef325'],
            'no hyphens' => ['3239baee3e7f785c0853f4302ef32570'],
            'a quoted identifier' => ['"' . self::IDENTIFIER . '"'],
        ];
    }

    public function testAnEmptyListIsRefused(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786958511);

        NodeIdentifiers::fromCommaSeparatedString('  ,  ');
    }

    public function testTheListIsHandedToThePipelineAsItWasRead(): void
    {
        // the pipeline passes it on as a prunner variable
        self::assertSame(
            self::IDENTIFIER . ',' . self::OTHER_IDENTIFIER,
            (string) NodeIdentifiers::fromCommaSeparatedString(self::IDENTIFIER . ' , ' . self::OTHER_IDENTIFIER)
        );
    }
}
