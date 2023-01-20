<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\PrepareContentRelease\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class ContentReleaseMetadata implements \JsonSerializable
{

    private PrunnerJobId $prunnerJobId;

    /**
     * @var \DateTimeInterface|null
     */
    private $startTime;

    /**
     * @var \DateTimeInterface|null
     */
    private $endTime;

    /**
     * @var \DateTimeInterface|null
     */
    private $switchTime;

    /**
     * @var PrunnerJobId[]
     */
    private $manualTransferJobIds;

    private NodeRenderingCompletionStatus $status;

    private ?string $workspaceName;

    private function __construct(
        PrunnerJobId $prunnerJobId,
        ?\DateTimeInterface $startTime,
        ?\DateTimeInterface $endTime,
        ?\DateTimeInterface $switchTime,
        ?NodeRenderingCompletionStatus $status,
        ?array $manualTransferJobIds = [],
        string $workspaceName = 'live'
    )
    {
        $this->prunnerJobId = $prunnerJobId;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->switchTime = $switchTime;
        $this->status = $status ?: NodeRenderingCompletionStatus::scheduled();
        $this->manualTransferJobIds = $manualTransferJobIds;
        $this->workspaceName = $workspaceName;
    }

    public static function create(PrunnerJobId $prunnerJobId, \DateTimeInterface $startTime, string $workspaceName = 'live'): self
    {
        return new self($prunnerJobId, $startTime, null, null, NodeRenderingCompletionStatus::scheduled(), [], $workspaceName);
    }

    public static function fromJsonString($metadataEncoded, ContentReleaseIdentifier $contentReleaseIdentifier): self
    {
        if (!is_string($metadataEncoded)) {
            throw new \Exception('Metadata is no string for ' . $contentReleaseIdentifier->getIdentifier());
        }
        $tmp = json_decode($metadataEncoded, true);
        if (!is_array($tmp)) {
            throw new \Exception('ContentReleaseMetadata cannot be constructed from: ' . $metadataEncoded);
        }

        return new self(
            PrunnerJobId::fromString($tmp['prunnerJobId']),
            ($tmp['startTime'] !== null) ? \DateTimeImmutable::createFromFormat(\DateTime::RFC3339_EXTENDED, $tmp['startTime']) : null,
            ($tmp['endTime'] !== null) ? \DateTimeImmutable::createFromFormat(\DateTime::RFC3339_EXTENDED, $tmp['endTime']) : null,
            ($tmp['switchTime'] !== null) ? \DateTimeImmutable::createFromFormat(\DateTime::RFC3339_EXTENDED, $tmp['switchTime']) : null,
            NodeRenderingCompletionStatus::fromString($tmp['status']),
            isset($tmp['manualTransferJobIds']) ? array_map(function (string $item) {
                return PrunnerJobId::fromString($item);
            }, json_decode($tmp['manualTransferJobIds'])) : [],
            $tmp['workspaceName'] ?? 'live'
        );
    }


    public function jsonSerialize(): array
    {
        return [
            'prunnerJobId' => $this->prunnerJobId->getIdentifier(),
            'startTime' => $this->startTime ? $this->startTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'endTime' => $this->endTime ? $this->endTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'switchTime' => $this->switchTime ? $this->switchTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'status' => $this->status,
            'manualTransferJobIds' => json_encode($this->manualTransferJobIds),
            'workspaceName' => $this->workspaceName
        ];
    }

    public function withEndTime(\DateTimeInterface $endTime): self
    {
        return new self($this->prunnerJobId, $this->startTime, $endTime, $this->switchTime, $this->status, $this->manualTransferJobIds, $this->workspaceName);
    }

    public function withSwitchTime(\DateTimeInterface $switchTime): self
    {
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $switchTime, $this->status, $this->manualTransferJobIds, $this->workspaceName);
    }

    public function withStatus(NodeRenderingCompletionStatus $status): self
    {
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $this->switchTime, $status, $this->manualTransferJobIds, $this->workspaceName);
    }

    public function withAdditionalManualTransferJobId(PrunnerJobId $prunnerJobId): self
    {
        $manualTransferIdArray = $this->getManualTransferJobIds();
        $manualTransferIdArray[] = $prunnerJobId;
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $this->switchTime, $this->status, $manualTransferIdArray, $this->workspaceName);
    }

    public function getPrunnerJobId(): PrunnerJobId
    {
        return $this->prunnerJobId;
    }

    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    public function getSwitchTime(): ?\DateTimeInterface
    {
        return $this->switchTime;
    }

    public function getStatus(): NodeRenderingCompletionStatus
    {
        return $this->status;
    }

    /**
     * @return PrunnerJobId[]
     */
    public function getManualTransferJobIds(): ?array
    {
        return $this->manualTransferJobIds;
    }

    public function getWorkspaceName(): ?string
    {
        return $this->workspaceName;
    }

}
