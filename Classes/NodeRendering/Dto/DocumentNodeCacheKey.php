<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

/**
 * Represents the top-level Fusion Cache identifier for a rendered node
 */
#[Flow\Proxy(false)]
final readonly class DocumentNodeCacheKey
{
    private array $arguments;

    private array $dimensions;

    private function __construct(
        private string $nodeIdentifier,
        array $dimensions,
        array $arguments,
    )
    {
        // we need to make this deterministic
        ksort($arguments);
        ksort($dimensions);
        $this->dimensions = $dimensions;
        $this->arguments = $arguments;
    }

    public static function fromNodeAndArguments(Node $node, array $arguments): self
    {
        return new self(
            $node->aggregateId->value,
            $node->dimensionSpacePoint->coordinates,
            $arguments,
        );
    }

    public static function fromEnumeratedNode(EnumeratedNode $enumeratedNode): self
    {
        return new self(
            $enumeratedNode->getNodeIdentifier(),
            $enumeratedNode->getDimensionsFromContextPath(),
            $enumeratedNode->getArguments(),
        );
    }

    public function redisKeyName(): string
    {
        // TODO: Add workspace name to cache entry to allow parallel releases, but `CacheUrlMappingAspect` has to provide node in correct workspace during rendering
        return preg_replace(
            '/[^a-zA-Z0-9-]/',
            '_',
            sprintf(
                'doc--%s-%s-%s',
                $this->nodeIdentifier,
                json_encode($this->dimensions),
                json_encode($this->arguments),
            ),
        );
    }

    public function fullyQualifiedRedisKeyName(string $identifierPrefix): string
    {
        return $identifierPrefix . 'Neos_Fusion_Content:entry:' . $this->redisKeyName();
    }
}
