<?php

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * @Flow\Proxy(false)
 */
final class EnumeratedNode implements \JsonSerializable
{

    /**
     * We extract the to-be-rendered dimensions and the current site from the context path. Other than that,
     * it is used NOT for rendering.
     *
     * You are NOT ALLOWED to rely on this stored context path as a whole during rendering, because it might have changed because of a move.
     *
     * @var string
     */
    protected $contextPath;

    /**
     * We identify the node by its identifier
     *
     * @var string
     */
    protected $nodeIdentifier;

    /**
     * @var array
     */
    protected $arguments;

    /**
     * The node type name
     *
     * @var string
     */
    protected $nodeTypeName;

    private function __construct(string $contextPath, string $nodeIdentifier, string $nodeTypeName, array $arguments)
    {
        $this->contextPath = $contextPath;
        $this->nodeIdentifier = $nodeIdentifier;
        $this->nodeTypeName = $nodeTypeName;
        $this->arguments = $arguments;
    }

    static public function fromNode(NodeInterface $node, array $arguments = []): self
    {
        return new self($node->getContextPath(), $node->getIdentifier(), $node->getNodeType()->getName(), $arguments);
    }

    static public function fromJsonString(string $enumeratedNodeString): self
    {
        $tmp = json_decode($enumeratedNodeString, true);
        if (!is_array($tmp)) {
            throw new \Exception('EnumeratedNode cannot be constructed from: ' . $enumeratedNodeString);
        }
        return new self($tmp['contextPath'], $tmp['nodeIdentifier'], $tmp['nodeTypeName'] ?? '', $tmp['arguments']);
    }

    public function jsonSerialize()
    {
        return [
            'contextPath' => $this->contextPath,
            'nodeIdentifier' => $this->nodeIdentifier,
            'nodeTypeName' => $this->nodeTypeName,
            'arguments' => $this->arguments
        ];
    }

    public function getSiteNodeNameFromContextPath(): string
    {
        if (preg_match('#^/sites/([^/@]*)#', $this->contextPath, $matches)) {
            return $matches[1];
        } else {
            throw new \Exception('Could not get site node name from context path "' . $this->contextPath . '"', 1495535171);
        }
    }

    public function getDimensionsFromContextPath(): array
    {
        $nodePathAndContext = NodePaths::explodeContextPath($this->contextPath);
        return $nodePathAndContext['dimensions'];
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
        return sprintf('%s %s %s(%s)', $this->nodeTypeName, $this->nodeIdentifier, $this->arguments ? http_build_query($this->arguments) . ' ' : '', $this->contextPath);
    }
}
