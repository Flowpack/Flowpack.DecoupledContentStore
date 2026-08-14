<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Fusion\ExceptionHandlers;

use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\Flow\Annotations as Flow;

class PublishingAwareContextDependentHandler extends \Neos\Fusion\Core\ExceptionHandlers\ContextDependentHandler
{
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
