<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Flowpack\DecoupledContentStore\Utility\Sparkline;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RenderingStatistics implements \JsonSerializable
{

    /**
     * @var int
     */
    protected $remainingJobs;

    /**
     * @var int
     */
    protected $totalJobs;

    /**
     * @var array
     */
    protected $renderingsPerSecond;

    /**
     * @var string
     */
    protected $svgSparkline;

    /**
     * @param int $remainingJobs
     * @param int $totalJobs
     */
    private function __construct(int $remainingJobs, int $totalJobs, array $renderingsPerSecond)
    {
        $this->remainingJobs = $remainingJobs;
        $this->totalJobs = $totalJobs;
        $this->renderingsPerSecond = $renderingsPerSecond;
        $this->svgSparkline = Sparkline::sparkline('', $renderingsPerSecond);
    }

    public static function create(int $remainingJobs, int $totalJobs, array $renderingsPerSecond): self
    {
        return new self($remainingJobs, $totalJobs, $renderingsPerSecond);
    }

    public static function fromJsonString($jsonString): self
    {
        if (!is_string($jsonString)) {
            return new self(-1, -1, []);
        }
        $tmp = json_decode($jsonString, true);
        if (!is_array($tmp)) {
            throw new \Exception('DocumentNodeCacheValues cannot be constructed from: ' . $jsonString);
        }
        return new self($tmp['remainingJobs'], $tmp['totalJobs'], $tmp['renderingsPerSecond']);
    }

    /**
     * @return int
     */
    public function getRemainingJobs(): int
    {
        return $this->remainingJobs;
    }

    public function getRenderedJobs(): int
    {
        return $this->totalJobs - $this->remainingJobs;
    }

    /**
     * @return int
     */
    public function getTotalJobs(): int
    {
        return $this->totalJobs;
    }

    /**
     * @return array
     */
    public function getRenderingsPerSecond(): array
    {
        return $this->renderingsPerSecond;
    }

    /**
     * @return string
     */
    public function getSvgSparkline(): string
    {
        return $this->svgSparkline;
    }

    public function jsonSerialize()
    {
        return [
            'remainingJobs' => $this->remainingJobs,
            'totalJobs' => $this->totalJobs,
            'renderingsPerSecond' => $this->renderingsPerSecond,
            'svgSparkline' => $this->svgSparkline
        ];
    }


}
