<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ContentReleaseDetails
{
    /**
     * @param RenderingStatistics[] $renderingStatistics
     * @param string[] $renderingErrors errors registered during rendering and validation, each "<exception message> - <JSON of additional data>"
     * @param Job[] $manualTransferJobs
     * @param float|null $contentReleaseSize size in megabytes, or NULL if it was not determined (yet)
     * @param bool $isRemoved a removed release is no longer registered, so it can neither be switched to nor transferred - only the keys it keeps after removal are left, and its enumeration is gone
     */
    public function __construct(
        public ContentReleaseIdentifier $contentReleaseIdentifier,
        public ?Job $job,
        public int $enumeratedDocumentNodesCount,
        public array $renderingStatistics,
        public array $renderingErrors,
        public bool $isActive,
        public array $manualTransferJobs,
        public ?float $contentReleaseSize = null,
        public bool $isRemoved = false,
    ) {}
}
