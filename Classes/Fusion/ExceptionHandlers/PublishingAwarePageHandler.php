<?php
namespace Flowpack\DecoupledContentStore\Fusion\ExceptionHandlers;

use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\Flow\Annotations as Flow;

class PublishingAwarePageHandler extends \Neos\Neos\Fusion\ExceptionHandlers\PageHandler {

    /**
     * @Flow\Inject
     * @var DocumentRenderer
     */
    protected $documentRenderer;

    /**
     * {@inheritdoc}
     */
    protected function handle($fusionPath, \Exception $exception, $referenceCode)
    {
        if ($this->documentRenderer->isRendering()) {
            throw $exception;
        } else {
            return parent::handle($fusionPath, $exception, $referenceCode);
        }
    }

}
