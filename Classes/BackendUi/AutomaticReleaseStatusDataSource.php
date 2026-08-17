<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Service\DataSource\AbstractDataSource;

/**
 * Publishes the state of the automatic release switch to the content module, where a warning is painted while
 * releases are paused.
 *
 * A data source rather than an action on the backend module: the module privilege covers every action of the module
 * controller, so a module action would be readable only by the very people who do not need the warning. Data sources
 * are granted to Neos.Neos:AbstractEditor, and they need no route of their own.
 *
 * The payload deliberately carries nothing but the switch state - no release identifiers, no node data - so that
 * making it readable by every backend user costs nothing. The warning itself is translated here rather than in the
 * script, because Neos exposes no translation API to plain JavaScript; the service controllers set the current
 * locale from the backend user's interface language, so this ends up in the language the reader chose.
 */
final class AutomaticReleaseStatusDataSource extends AbstractDataSource
{
    /**
     * @var string
     */
    protected static $identifier = 'flowpack-decoupledcontentstore-automatic-release-status';

    #[Flow\Inject]
    protected AutomaticReleaseSwitchService $automaticReleaseSwitchService;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected BackendDateFormatter $backendDateFormatter;

    /**
     * @param array<mixed> $arguments
     * @return array<string, mixed>
     */
    public function getData(?NodeInterface $node = null, array $arguments = []): array
    {
        $pauseState = $this->automaticReleaseSwitchService->getPauseState();

        if ($pauseState === null) {
            return ['paused' => false];
        }

        return [
            'paused' => true,
            'message' => (string)$this->translator->translateById(
                'automaticReleases.paused.contentModuleWarning',
                [
                    $this->backendDateFormatter->format($pauseState->getPausedAt()),
                    $pauseState->getSuppressedReleaseCount(),
                ],
                null,
                null,
                'Main',
                'Flowpack.DecoupledContentStore'
            ),
        ];
    }
}
