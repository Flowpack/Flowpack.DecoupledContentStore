<?php
namespace Flowpack\DecoupledContentStore\Exception;

use Neos\ContentRepository\Domain\Model\NodeInterface;

class RenderingException extends \Flowpack\DecoupledContentStore\Exception
{

    /**
     * @var NodeInterface
     */
    protected $node;

    /**
     * @var string
     */
    protected $nodeUri;


    /**
     * @param string $message
     * @param NodeInterface $node
     * @param string $nodeUri
     * @param int $code
     * @param \Exception $previous
     */
    public function __construct($message, NodeInterface $node, $nodeUri, $code = 0, \Exception $previous = null)
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