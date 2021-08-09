<?php
namespace Flowpack\DecoupledContentStore\Controller;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
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

    public function detailsAction(string $jobIdentifier, ?string $detailTaskName = '')
    {
        $jobIdentifier = JobId::create($jobIdentifier);
        $this->view->assign('detailsData', $this->backendUiDataService->loadDetailsData($jobIdentifier));
        if ($detailTaskName !== '') {
            $this->view->assign('detailTaskName', $detailTaskName);
            $this->view->assign('jobLogs', $this->prunnerApiService->loadJobLogs($jobIdentifier, $detailTaskName));
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
     */
    public function showAction($release)
    {
        $releaseIdentifier = $release;

        $status = $this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_STATUS);
        $currentReleaseIdentifier = $this->contentStore->getCurrentRelease();
        $startDate = $this->timestampToDate($this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_START_DATE));
        $switchDate = $this->timestampToDate($this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_SWITCH_DATE));
        $stopDate = $this->timestampToDate($this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_STOP_DATE));
        $removedDate = $this->timestampToDate($this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_REMOVED));
        $removedReason = $this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_REMOVED_REASON);
        $urls = $this->contentStore->getUrls($releaseIdentifier);
        $queuedUrlCount = $this->contentStore->getQueuedUrlsCount($releaseIdentifier);
        $renderedUrls = $this->contentStore->getRenderedUrls($releaseIdentifier);
        $errors = $this->contentStore->getErrors($releaseIdentifier);
        $errorCount = $this->contentStore->getReleaseMeta($releaseIdentifier, ContentStore::META_ERROR_COUNT);
        $renderedUrlsIndex = array_flip($renderedUrls);

        if ($urls === ['']) {
            $urls = [];
        }
        if ($renderedUrls === ['']) {
            $renderedUrls = [];
        }

        $urlInfo = [];
        $allUrls = array_unique(array_merge($urls, $renderedUrls));
        sort($allUrls);
        foreach ($allUrls as $url) {
            $urlInfo[] = [
                'url' => $url,
                'rendered' => isset($renderedUrlsIndex[$url])
            ];
        }

        $release = [
            'status' => $status,
            'identifier' => $releaseIdentifier,
            'current' => (string)$releaseIdentifier === (string)$currentReleaseIdentifier,
            'removedDate' => $removedDate,
            'removedReason' => $removedReason,
            'startDate' => $startDate,
            'switchDate' => $switchDate,
            'stopDate' => $stopDate,
            'urlInfo' => $urlInfo,
            'urlCount' => count($urls),
            'queuedUrlCount' => $queuedUrlCount,
            'renderedUrlCount' => count($renderedUrls),
            'errors' => $errors,
            'errorCount' => $errorCount
        ];
        $release['progress'] = $this->getProgress($release);

        $this->view->assign('release', $release);
    }

    /**
     * @param integer $release
     * @return string
     */
    public function removeAction($release)
    {
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
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatus(405);
            return 'Method not allowed';
        }

        $releaseIdentifier = $release;

        $this->contentStore->switchCurrentRelease($releaseIdentifier, true);

        $this->redirect('index');
    }
}
