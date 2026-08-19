<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Exception;

use Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class RenderingException extends \Flowpack\DecoupledContentStore\Exception
{
    private string $nodeUri;

    /**
     * @param string $message
     * @param NodeInterface $node
     * @param string $nodeUri
     * @param int $code
     * @param Exception $previous
     */
    public function __construct(
        string $message,
        NodeInterface $node,
        $nodeUri,
        int $code = 0,
        ?Exception $previous = null,
    ) {
        $this->nodeUri = $nodeUri;
        parent::__construct($message, $code, $previous);
    }

    public function getNodeUri(): string
    {
        return $this->nodeUri;
    }
}
