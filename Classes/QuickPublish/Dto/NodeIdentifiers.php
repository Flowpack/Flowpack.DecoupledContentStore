<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish\Dto;

use Flowpack\DecoupledContentStore\Exception;
use Neos\Flow\Annotations as Flow;

/**
 * The node identifiers a quick content release was asked to publish.
 *
 * They travel from a text field in the backend through a prunner variable into a shell command, so every token is
 * checked against the identifier format before it is used anywhere - an unchecked value would be a command
 * injection.
 *
 * @implements \IteratorAggregate<int, string>
 */
#[Flow\Proxy(false)]
final class NodeIdentifiers implements \IteratorAggregate, \JsonSerializable
{
    private const IDENTIFIER_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @var array<int, string>
     */
    private array $identifiers;

    /**
     * @param array<int, string> $identifiers
     */
    private function __construct(array $identifiers)
    {
        $this->identifiers = $identifiers;
    }

    /**
     * The form the pipeline passes them in.
     *
     * @throws Exception if the list is empty or holds anything which is not a node identifier
     */
    public static function fromCommaSeparatedString(string $nodeIdentifiers): self
    {
        return self::fromTokens(explode(',', $nodeIdentifiers));
    }

    /**
     * What somebody pasted into the backend form - one identifier per line, or separated by commas, or both.
     *
     * @throws Exception if the list is empty or holds anything which is not a node identifier
     */
    public static function fromUserInput(string $nodeIdentifiers): self
    {
        $tokens = preg_split('/[\s,;]+/', $nodeIdentifiers);

        return self::fromTokens($tokens === false ? [] : $tokens);
    }

    /**
     * @param array<int, string> $tokens
     * @throws Exception if the list is empty or holds anything which is not a node identifier
     */
    private static function fromTokens(array $tokens): self
    {
        $identifiers = [];

        foreach ($tokens as $identifier) {
            $identifier = trim($identifier);
            if ($identifier === '') {
                continue;
            }
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new Exception(sprintf('"%s" is not a node identifier.', $identifier), 1786958510);
            }
            // an identifier given twice would be rendered twice
            if (!in_array($identifier, $identifiers, true)) {
                $identifiers[] = $identifier;
            }
        }

        if ($identifiers === []) {
            throw new Exception('No node identifiers given.', 1786958511);
        }

        return new self($identifiers);
    }

    /**
     * @return \Traversable<int, string>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->identifiers;
    }

    /**
     * @return array<int, string>
     */
    public function jsonSerialize(): array
    {
        return $this->identifiers;
    }

    public function count(): int
    {
        return count($this->identifiers);
    }

    public function __toString(): string
    {
        return implode(',', $this->identifiers);
    }
}
