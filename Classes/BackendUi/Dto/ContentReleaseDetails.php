<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingProgress;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Annotations as Flow;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\Fusion\Core\Cache\ContentCache;

/**
 * @Flow\Proxy(false)
 */
class ContentReleaseDetails
{
    private ContentReleaseIdentifier $contentReleaseIdentifier;
    private Job $job;
    private int $enumeratedDocumentNodesCount;
    private RenderingProgress $renderingProgress;
    private string $svgSparkline;

    public function __construct(ContentReleaseIdentifier $contentReleaseIdentifier, Job $job, int $enumeratedDocumentNodesCount, RenderingProgress $renderingProgress, string $svgSparkline)
    {
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->job = $job;
        $this->enumeratedDocumentNodesCount = $enumeratedDocumentNodesCount;
        $this->renderingProgress = $renderingProgress;
        $this->svgSparkline = $svgSparkline;
    }

    /**
     * @return ContentReleaseIdentifier
     */
    public function getContentReleaseIdentifier(): ContentReleaseIdentifier
    {
        return $this->contentReleaseIdentifier;
    }

    /**
     * @return Job
     */
    public function getJob(): Job
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
     * @return RenderingProgress
     */
    public function getRenderingProgress(): RenderingProgress
    {
        return $this->renderingProgress;
    }

    /**
     * @return string
     */
    public function getSvgSparkline(): string
    {
        return $this->svgSparkline;
    }
}