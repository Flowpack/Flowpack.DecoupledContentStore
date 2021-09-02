<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\PrepareContentRelease\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
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

    private function __construct(PrunnerJobId $prunnerJobId, ?\DateTimeInterface $startTime, ?\DateTimeInterface $endTime, ?\DateTimeInterface $switchTime, ?NodeRenderingCompletionStatus $status, ?array $manualTransferJobIds = [])
    {
        $this->prunnerJobId = $prunnerJobId;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->switchTime = $switchTime;
        $this->status = $status ?: NodeRenderingCompletionStatus::scheduled();
        $this->manualTransferJobIds = $manualTransferJobIds;
    }


    public static function create(PrunnerJobId $prunnerJobId, \DateTimeInterface $startTime): self
    {
        return new self($prunnerJobId, $startTime, null, null, NodeRenderingCompletionStatus::scheduled());
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
            }, json_decode($tmp['manualTransferJobIds'])) : []
        );
    }


    public function jsonSerialize()
    {
        return [
            'prunnerJobId' => $this->prunnerJobId->getIdentifier(),
            'startTime' => $this->startTime ? $this->startTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'endTime' => $this->endTime ? $this->endTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'switchTime' => $this->switchTime ? $this->switchTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'status' => $this->status,
            'manualTransferJobIds' => json_encode($this->manualTransferJobIds)
        ];
    }

    public function withEndTime(\DateTimeInterface $endTime): self
    {
        return new self($this->prunnerJobId, $this->startTime, $endTime, $this->switchTime, $this->status);
    }

    public function withSwitchTime(\DateTimeInterface $switchTime): self
    {
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $switchTime, $this->status);
    }

    public function withStatus(NodeRenderingCompletionStatus $status): self
    {
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $this->switchTime, $status);
    }

    public function withAdditionalManualTransferJobId(PrunnerJobId $prunnerJobId): self
    {
        $manualTransferIdArray = self::getManualTransferJobIds();
        $manualTransferIdArray[] = $prunnerJobId;
        return new self($this->prunnerJobId, $this->startTime, $this->endTime, $this->switchTime, $this->status, $manualTransferIdArray);
    }

    /**
     * @return PrunnerJobId
     */
    public function getPrunnerJobId(): PrunnerJobId
    {
        return $this->prunnerJobId;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getSwitchTime(): ?\DateTimeInterface
    {
        return $this->switchTime;
    }

    /**
     * @return NodeRenderingCompletionStatus
     */
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

}
