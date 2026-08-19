<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * One dimension variant of one document node, as the confirmation page shows it.
 *
 * A node identifier which resolves nowhere also becomes a row rather than an error: somebody pasting five
 * identifiers wants to see which of them is the wrong one, not just that one of them is.
 */
#[Flow\Proxy(false)]
final class QuickPublishPreviewRow
{
    private string $nodeIdentifier;

    private string $title;

    private string $nodePath;

    private string $dimensions;

    private string $nodeTypeName;

    private ?string $backendUri;

    private ?string $skipReason;

    private function __construct(
        string $nodeIdentifier,
        string $title,
        string $nodePath,
        string $dimensions,
        string $nodeTypeName,
        ?string $backendUri,
        ?string $skipReason,
    ) {
        $this->nodeIdentifier = $nodeIdentifier;
        $this->title = $title;
        $this->nodePath = $nodePath;
        $this->dimensions = $dimensions;
        $this->nodeTypeName = $nodeTypeName;
        $this->backendUri = $backendUri;
        $this->skipReason = $skipReason;
    }

    public static function forNode(
        string $nodeIdentifier,
        string $title,
        string $nodePath,
        string $dimensions,
        string $nodeTypeName,
        ?string $backendUri,
        ?string $skipReason,
    ): self {
        return new self($nodeIdentifier, $title, $nodePath, $dimensions, $nodeTypeName, $backendUri, $skipReason);
    }

    public static function forNodeWhichCannotBeFound(string $nodeIdentifier): self
    {
        return new self($nodeIdentifier, '', '', '', '', null, 'not found in any site and dimension');
    }

    public function getNodeIdentifier(): string
    {
        return $this->nodeIdentifier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getNodePath(): string
    {
        return $this->nodePath;
    }

    public function getDimensions(): string
    {
        return $this->dimensions;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getBackendUri(): ?string
    {
        return $this->backendUri;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function isPublished(): bool
    {
        return $this->skipReason === null;
    }
}
