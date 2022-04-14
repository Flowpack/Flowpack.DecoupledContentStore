<?php

namespace Flowpack\DecoupledContentStore\BackendUi\FusionObjects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\FlashMessage\FlashMessageService;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

class RetrieveFlashMessagesImplementation extends AbstractFusionObject
{
    /**
     * @Flow\Inject
     * @var FlashMessageService
     */
    protected $flashMessageService;

    public function evaluate()
    {
        return $this->flashMessageService->getFlashMessageContainerForRequest($this->runtime->getControllerContext()->getRequest())->getMessagesAndFlush();
    }
}
