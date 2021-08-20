<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class ContentReleaseDetails
{
    private ContentReleaseIdentifier $contentReleaseIdentifier;
    private ?Job $job;
    private int $enumeratedDocumentNodesCount;
    private int $renderingErrorCount;

    /**
     * @var RenderingStatistics[]
     */
    private array $renderingStatistics;

    public function __construct(ContentReleaseIdentifier $contentReleaseIdentifier, ?Job $job, int $enumeratedDocumentNodesCount, array $renderingStatistics, int $renderingErrorCount)
    {
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->job = $job;
        $this->enumeratedDocumentNodesCount = $enumeratedDocumentNodesCount;
        $this->renderingStatistics = $renderingStatistics;
        $this->renderingErrorCount = $renderingErrorCount;
    }

    /**
     * @return ContentReleaseIdentifier
     */
    public function getContentReleaseIdentifier(): ContentReleaseIdentifier
    {
        return $this->contentReleaseIdentifier;
    }

    /**
     * @return Job|null
     */
    public function getJob(): ?Job
    {
        return $this->job;
    }

    /**
     * @return int
     */
    public function getEnumeratedDocumentNodesCount(): int
    {
        return $this->enumeratedDocumentNodesCount;
    }

    /**
     * @return RenderingStatistics[]
     */
    public function getRenderingStatistics(): array
    {
        return $this->renderingStatistics;
    }

    /**
     * @return int
     */
    public function getRenderingErrorCount(): int
    {
        return $this->renderingErrorCount;
    }
    
}
