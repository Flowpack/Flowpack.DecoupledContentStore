<?php
namespace Flowpack\DecoupledContentStore\Controller;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\JobId;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;

class BackendController extends \Neos\Flow\Mvc\Controller\ActionController
{

    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    /**
     * @Flow\Inject
     * @var BackendUiDataService
     */
    protected $backendUiDataService;

    protected $defaultViewObjectName = FusionView::class;

    public function indexAction()
    {
        $this->view->assign('overviewData', $this->backendUiDataService->loadBackendOverviewData());
    }

    public function detailsAction(string $contentReleaseIdentifier, ?string $detailTaskName = '')
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);

        $detailsData = $this->backendUiDataService->loadDetailsData($contentReleaseIdentifier);
        $this->view->assign('detailsData', $detailsData);
        if ($detailTaskName !== '') {
            $this->view->assign('detailTaskName', $detailTaskName);
            $this->view->assign('jobLogs', $this->prunnerApiService->loadJobLogs($detailsData->getJob()->getId(), $detailTaskName));
        }
    }


    public function publishAllAction()
    {
        $this->contentReleaseManager->cancelAllRunningContentReleases();
        $this->contentReleaseManager->startFullContentRelease();
        $this->redirect('index');
    }

    /**
     * @param integer $release
     * @return string
     */
    public function removeAction($release)
    {
        // TODO!!!
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatus(405);
            return 'Method not allowed';
        }

        $releaseIdentifier = $release;

        $this->contentStore->removeRelease($releaseIdentifier, 'Manual removal in content store administration module');

        $this->redirect('index');
    }

    /**
     * @param integer $release
     * @return string
     */
    public function switchAction($release)
    {
        // TODO!!!
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatus(405);
            return 'Method not allowed';
        }

        $releaseIdentifier = $release;

        $this->contentStore->switchCurrentRelease($releaseIdentifier, true);

        $this->redirect('index');
    }
}
