<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Represents the top-level Fusion Cache identifier for a rendered node
 *
 * @Flow\Proxy(false)
 */
final class DocumentNodeCacheKey
{
    /**
     * @var string
     */
    protected $nodeIdentifier;

    /**
     * @var array
     */
    protected $dimensions;

    /**
     * @var array
     */
    protected $arguments;

    private function __construct(string $nodeIdentifier, array $dimensions, array $arguments)
    {
        // we need to make this deterministic
        ksort($arguments);
        ksort($dimensions);

        $this->nodeIdentifier = $nodeIdentifier;
        $this->dimensions = $dimensions;
        $this->arguments = $arguments;
    }


    public static function fromNodeAndArguments(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, array $arguments): self
    {
        return new self($node->nodeAggregateId, $node->getContext()->getDimensions(), $arguments);
    }

    public static function fromEnumeratedNode(EnumeratedNode $enumeratedNode)
    {
        return new self($enumeratedNode->getNodeIdentifier(), $enumeratedNode->getDimensionsFromContextPath(), $enumeratedNode->getArguments());
    }

    public function redisKeyName(): string
    {
        return preg_replace('/[^a-zA-Z0-9-]/', '_', sprintf('doc--%s-%s-%s', $this->nodeIdentifier, json_encode($this->dimensions), json_encode($this->arguments)));
    }

    public function fullyQualifiedRedisKeyName(string $identifierPrefix): string
    {
        return $identifierPrefix . 'Neos_Fusion_Content:entry:' . $this->redisKeyName();
    }
}
