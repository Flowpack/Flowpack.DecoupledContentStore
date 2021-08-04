<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RenderingProgress implements \JsonSerializable
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
     * @param int $remainingJobs
     * @param int $totalJobs
     */
    private function __construct(int $remainingJobs, int $totalJobs)
    {
        $this->remainingJobs = $remainingJobs;
        $this->totalJobs = $totalJobs;
    }


    public static function create(int $remainingJobs, int $totalJobs): self
    {
        return new self($remainingJobs, $totalJobs);
    }

    public static function fromJsonString($jsonString): self
    {
        if (!is_string($jsonString)) {
            return new self(-1, -1);
        }
        $tmp = json_decode($jsonString, true);
        if (!is_array($tmp)) {
            throw new \Exception('DocumentNodeCacheValues cannot be constructed from: ' . $jsonString);
        }
        return new self($tmp['remainingJobs'], $tmp['totalJobs']);
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

    public function jsonSerialize()
    {
        return [
            'remainingJobs' => $this->remainingJobs,
            'totalJobs' => $this->totalJobs
        ];
    }


}