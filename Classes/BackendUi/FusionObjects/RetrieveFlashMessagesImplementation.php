<?php

namespace Flowpack\DecoupledContentStore\BackendUi\FusionObjects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\FlashMessage\FlashMessageService;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\Error\Messages\Message;

class RetrieveFlashMessagesImplementation extends AbstractFusionObject
{
    /**
     * @Flow\Inject
     * @var FlashMessageService
     */
    protected $flashMessageService;

    public function evaluate()
    {
        $messages = $this->flashMessageService->getFlashMessageContainerForRequest($this->runtime->getControllerContext()->getRequest())->getMessagesAndFlush();
        return array_map(function (Message $message) {
            return [
                'message' => $message->getMessage(),
                'severity' => $message->getSeverity()
            ];
        }, $messages);
    }
}
