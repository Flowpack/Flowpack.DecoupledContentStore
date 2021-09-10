<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
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
    private int $iterationsCount;
    private int $errorCount;
    private float $progress;
    private int $renderedUrlCount;
    private bool $isActive;
    private float $releaseSize;

    public function __construct(ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseMetadata $metadata,
                                int $enumeratedDocumentNodesCount, int $iterationsCount, int $errorCount,
                                float $progress, int $renderedUrlCount, bool $isActive, float $releaseSize)
    {
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->metadata = $metadata;
        $this->enumeratedDocumentNodesCount = $enumeratedDocumentNodesCount;
        $this->iterationsCount = $iterationsCount;
        $this->errorCount = $errorCount;
        $this->progress = $progress;
        $this->renderedUrlCount = $renderedUrlCount;
        $this->isActive = $isActive;
        $this->releaseSize = $releaseSize;
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
     * @return int
     */
    public function getIterationsCount(): int
    {
        return $this->iterationsCount;
    }

    /**
     * @return int
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * @return float
     */
    public function getProgress(): float
    {
        return $this->progress;
    }

    /**
     * @return int
     */
    public function getRenderedUrlCount(): int
    {
        return $this->renderedUrlCount;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return float
     */
    public function getReleaseSize(): float
    {
        return $this->releaseSize;
    }

}
