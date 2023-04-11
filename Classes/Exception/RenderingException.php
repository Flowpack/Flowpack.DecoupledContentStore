<?php
namespace Flowpack\DecoupledContentStore\Exception;

use Neos\ContentRepository\Domain\Model\NodeInterface;

class RenderingException extends \Flowpack\DecoupledContentStore\Exception
{

    /**
     * @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     */
    protected $node;

    /**
     * @var string
     */
    protected $nodeUri;


    /**
     * @param string $message
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @param string $nodeUri
     * @param int $code
     * @param \Exception $previous
     */
    public function __construct($message, \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, $nodeUri, $code = 0, \Exception $previous = null)
    {
        $this->node = $node;
        $this->nodeUri = $nodeUri;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string
     */
    public function getNodeUri()
    {
        return $this->nodeUri;
    }
}