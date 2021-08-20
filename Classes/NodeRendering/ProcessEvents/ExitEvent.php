<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Neos\Flow\Annotations as Flow;

/**
 * A process
 *
 * @Flow\Proxy(false)
 */
final class ExitEvent implements InterruptibleProcessRuntimeEventInterface
{
    /**
     * @var int
     */
    protected $statusCode;

    private function __construct(int $statusCode)
    {
        $this->statusCode = $statusCode;
    }

    public static function createWithStatusCode(int $statusCode): self
    {
        return new self($statusCode);
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}