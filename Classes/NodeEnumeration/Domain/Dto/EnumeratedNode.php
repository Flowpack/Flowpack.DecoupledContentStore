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

    private function __construct(string $contextPath, string $nodeIdentifier, array $arguments)
    {
        $this->contextPath = $contextPath;
        $this->nodeIdentifier = $nodeIdentifier;
        $this->arguments = $arguments;
    }


    static public function fromNode(NodeInterface $node): self
    {
        return new self($node->getContextPath(), $node->getIdentifier(), []);
    }

    static public function fromJsonString(string $enumeratedNodeString): self
    {
        $tmp = json_decode($enumeratedNodeString, true);
        if (!is_array($tmp)) {
            throw new \Exception('EnumeratedNode cannot be constructed from: ' . $enumeratedNodeString);
        }
        return new self($tmp['contextPath'], $tmp['nodeIdentifier'], $tmp['arguments']);
    }

    public function jsonSerialize()
    {
        return [
            'contextPath' => $this->contextPath,
            'nodeIdentifier' => $this->nodeIdentifier,
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

    /**
     * @return string
     */
    public function getNodeIdentifier(): string
    {
        return $this->nodeIdentifier;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function debugString(): string
    {
        return sprintf('Node %s (%s)', $this->nodeIdentifier, $this->contextPath);
    }
}