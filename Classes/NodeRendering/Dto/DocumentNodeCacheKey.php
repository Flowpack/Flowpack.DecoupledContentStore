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
     * @var string
     */
    protected $workspaceName;

    /**
     * @var array
     */
    protected $arguments;

    private function __construct(string $nodeIdentifier, array $dimensions, string $workspaceName, array $arguments)
    {
        // we need to make this deterministic
        ksort($arguments);
        ksort($dimensions);

        $this->nodeIdentifier = $nodeIdentifier;
        $this->dimensions = $dimensions;
        $this->workspaceName = $workspaceName;
        $this->arguments = $arguments;
    }


    public static function fromNodeAndArguments(NodeInterface $node, array $arguments): self
    {
        return new self($node->getIdentifier(), $node->getContext()->getDimensions(), $node->getWorkspace()->getName(), $arguments);
    }

    public static function fromEnumeratedNode(EnumeratedNode $enumeratedNode)
    {
        return new self($enumeratedNode->getNodeIdentifier(), $enumeratedNode->getDimensionsFromContextPath(), $enumeratedNode->getWorkspaceNameFromContextPath(), $enumeratedNode->getArguments());
    }

    public function redisKeyName(): string
    {
        // TODO: Add workspace name to cache entry to allow parallel releases, but `CacheUrlMappingAspect` has to provide node in correct workspace during rendering
        return preg_replace('/[^a-zA-Z0-9-]/', '_', sprintf('doc--%s-%s-%s', $this->nodeIdentifier, json_encode($this->dimensions), json_encode($this->arguments)));
    }

    public function fullyQualifiedRedisKeyName(string $identifierPrefix): string
    {
        return $identifierPrefix . 'Neos_Fusion_Content:entry:' . $this->redisKeyName();
    }
}
