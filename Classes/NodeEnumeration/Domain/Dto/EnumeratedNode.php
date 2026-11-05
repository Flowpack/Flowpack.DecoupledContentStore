<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto;

use Exception;
use JsonSerializable;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final class EnumeratedNode implements JsonSerializable
{
    /**
     * We extract the to-be-rendered dimensions and the current site from the context path. Other than that,
     * it is used NOT for rendering.
     *
     * You are NOT ALLOWED to rely on this stored context path as a whole during rendering, because it might have changed because of a move.
     */
    private string $contextPath;

    /**
     * We identify the node by its identifier
     */
    private string $nodeIdentifier;

    private array $arguments;

    private string $nodeTypeName;

    /**
     * The renderer implementation to use for this EnumeratedNode;
     * a key from Settings at Flowpack.DecoupledContentStore.extensions.documentRenderers.[key]
     */
    public readonly string $rendererId;

    private function __construct(
        string $contextPath,
        string $nodeIdentifier,
        string $nodeTypeName,
        array $arguments,
        string $rendererId = '',
    ) {
        $this->contextPath = $contextPath;
        $this->nodeIdentifier = $nodeIdentifier;
        $this->nodeTypeName = $nodeTypeName;
        $this->arguments = $arguments;
        $this->rendererId = $rendererId;
    }

    public static function fromNode(Node $node, array $arguments = []): self
    {
        // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.
        return new self(
            NodeAddress::fromNode($node)->toJson(),
            $node->aggregateId->value,
            $node->nodeTypeName->value,
            $arguments,
            '',
        );
    }

    /**
     * @throws Exception
     */
    public static function fromJsonString(string $enumeratedNodeString): self
    {
        $tmp = json_decode($enumeratedNodeString, true);
        if (!is_array($tmp)) {
            throw new Exception('EnumeratedNode cannot be constructed from: ' . $enumeratedNodeString);
        }
        return new self(
            $tmp['contextPath'],
            $tmp['nodeIdentifier'],
            $tmp['nodeTypeName'] ?? '',
            $tmp['arguments'],
            $tmp['rendererId'],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'contextPath' => $this->contextPath,
            'nodeIdentifier' => $this->nodeIdentifier,
            'nodeTypeName' => $this->nodeTypeName,
            'arguments' => $this->arguments,
            'rendererId' => $this->rendererId,
        ];
    }

    /**
     * @throws Exception
     */
    public function getSiteNodeNameFromContextPath(): string
    {
        if (preg_match('#^/sites/([^/@]*)#', $this->contextPath, $matches)) {
            return $matches[1];
        } else {
            throw new Exception(
                'Could not get site node name from context path "' . $this->contextPath . '"',
                1495535171,
            );
        }
    }

    /**
     * @return array<string,string>
     */
    public function getDimensionsFromContextPath(): array
    {
        return NodeAddress::fromJsonString($this->contextPath)->dimensionSpacePoint->coordinates;
    }

    public function getWorkspaceNameFromContextPath(): string
    {
        return NodeAddress::fromJsonString($this->contextPath)->workspaceName->value;
    }

    public function getNodeIdentifier(): string
    {
        return $this->nodeIdentifier;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function debugString(): string
    {
        return sprintf(
            '%s %s %s(%s) - %s',
            $this->nodeTypeName,
            $this->nodeIdentifier,
            $this->arguments ? http_build_query($this->arguments) . ' ' : '',
            $this->contextPath,
            $this->rendererId,
        );
    }

    public function withRendererId(string $rendererId): self
    {
        return new self($this->contextPath, $this->nodeIdentifier, $this->nodeTypeName, $this->arguments, $rendererId);
    }
}
