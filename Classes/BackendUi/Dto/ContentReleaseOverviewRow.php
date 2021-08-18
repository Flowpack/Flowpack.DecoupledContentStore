<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingProgress;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class ContentReleaseOverviewRow
{
    private ContentReleaseIdentifier $contentReleaseIdentifier;
    private ContentReleaseMetadata $metadata;
    private int $enumeratedDocumentNodesCount;
    private RenderingProgress $renderingProgress;

    public function __construct(ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseMetadata $metadata, int $enumeratedDocumentNodesCount, RenderingProgress $renderingProgress)
    {
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->metadata = $metadata;
        $this->enumeratedDocumentNodesCount = $enumeratedDocumentNodesCount;
        $this->renderingProgress = $renderingProgress;
    }

    /**
     * @return ContentReleaseMetadata
     */
    public function getMetadata(): ContentReleaseMetadata
    {
        return $this->metadata;
    }

    /**
     * @return ContentReleaseIdentifier
     */
    public function getContentReleaseIdentifier(): ContentReleaseIdentifier
    {
        return $this->contentReleaseIdentifier;
    }

    /**
     * @return int
     */
    public function getEnumeratedDocumentNodesCount(): int
    {
        return $this->enumeratedDocumentNodesCount;
    }

    /**
     * @return RenderingProgress
     */
    public function getRenderingProgress(): RenderingProgress
    {
        return $this->renderingProgress;
    }
}