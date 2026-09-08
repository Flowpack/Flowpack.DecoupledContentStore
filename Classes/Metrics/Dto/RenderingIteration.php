<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics\Dto;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * How much rendering happened in one iteration of the NodeRenderOrchestrator's loop.
 */
#[Flow\Proxy(false)]
final readonly class RenderingIteration implements JsonSerializable
{
    private function __construct(
        public int $iteration,
        public int $scheduledRenderings,
        public int $completedRenderings,
    ) {}

    public static function fromRenderingStatistics(int $iteration, RenderingStatistics $renderingStatistics): self
    {
        return new self(
            $iteration,
            $renderingStatistics->getTotalJobs(),
            $renderingStatistics->getRenderedJobs(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'iteration' => $this->iteration,
            'scheduledRenderings' => $this->scheduledRenderings,
            'completedRenderings' => $this->completedRenderings,
        ];
    }
}
