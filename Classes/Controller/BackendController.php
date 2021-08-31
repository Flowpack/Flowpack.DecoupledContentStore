<?php
namespace Flowpack\DecoupledContentStore\Controller;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\Prunner\PrunnerApiService;
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

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    /**
     * @Flow\InjectConfiguration("redisContentStores")
     * @var array
     */
    protected $redisContentStores;

    protected $defaultViewObjectName = FusionView::class;

    public function indexAction(?string $contentStore = null)
    {
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();

        $this->view->assign('contentStore', $contentStore->getIdentifier());
        $this->view->assign('overviewData', $this->backendUiDataService->loadBackendOverviewData($contentStore));
        $this->view->assign('redisContentStores', array_keys($this->redisContentStores));
    }

    public function detailsAction(string $contentReleaseIdentifier, ?string $contentStore = null, ?string $detailTaskName = '')
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();

        $this->view->assign('contentStore', $contentStore->getIdentifier());

        $detailsData = $this->backendUiDataService->loadDetailsData($contentReleaseIdentifier, $contentStore);
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
