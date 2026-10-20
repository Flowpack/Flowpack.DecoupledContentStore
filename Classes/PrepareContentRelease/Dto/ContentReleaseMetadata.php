<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\PrepareContentRelease\Dto;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ContentReleaseMetadata implements JsonSerializable
{
    /**
     * @param PrunnerJobId[] $manualTransferJobIds
     * @param float|null $contentReleaseSize size of the content release in Redis, in megabytes. Determined once
     *     when the release is finished, as calculating it is expensive.
     */
    private function __construct(
        public PrunnerJobId $prunnerJobId,
        public ?DateTimeInterface $startTime,
        public ?DateTimeInterface $endTime,
        public ?DateTimeInterface $switchTime,
        public NodeRenderingCompletionStatus $status,
        public array $manualTransferJobIds = [],
        public string $workspaceName = 'live',
        public ?string $accountId = 'cli',
        public ?float $contentReleaseSize = null,
    ) {}

    public static function create(
        PrunnerJobId $prunnerJobId,
        DateTimeInterface $startTime,
        string $workspace = 'live',
        string $accountId = 'cli',
    ): self {
        return new self(
            $prunnerJobId,
            $startTime,
            null,
            null,
            NodeRenderingCompletionStatus::scheduled(),
            [],
            $workspace,
            $accountId,
        );
    }

    /**
     * @throws Exception
     */
    public static function fromJsonString(
        mixed $metadataEncoded,
        ContentReleaseIdentifier $contentReleaseIdentifier,
    ): self {
        if (!is_string($metadataEncoded)) {
            throw new Exception('Metadata is no string for ' . $contentReleaseIdentifier->getIdentifier());
        }
        $tmp = json_decode($metadataEncoded, true);
        if (!is_array($tmp)) {
            throw new Exception('ContentReleaseMetadata cannot be constructed from: ' . $metadataEncoded);
        }

        return new self(
            PrunnerJobId::fromString($tmp['prunnerJobId']),
            $tmp['startTime'] !== null
                ? DateTimeImmutable::createFromFormat(DateTime::RFC3339_EXTENDED, $tmp['startTime'])
                : null,
            $tmp['endTime'] !== null
                ? DateTimeImmutable::createFromFormat(DateTime::RFC3339_EXTENDED, $tmp['endTime'])
                : null,
            $tmp['switchTime'] !== null
                ? DateTimeImmutable::createFromFormat(DateTime::RFC3339_EXTENDED, $tmp['switchTime'])
                : null,
            NodeRenderingCompletionStatus::fromString($tmp['status']),
            isset($tmp['manualTransferJobIds']) ? array_map(function (string $item): PrunnerJobId {
                    return PrunnerJobId::fromString($item);
                }, json_decode($tmp['manualTransferJobIds'])) : [],
            $tmp['workspaceName'] ?? 'live',
            key_exists('accountId', $tmp) ? $tmp['accountId'] : 'cli',
            isset($tmp['contentReleaseSize']) ? (float) $tmp['contentReleaseSize'] : null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'prunnerJobId' => $this->prunnerJobId->getIdentifier(),
            'startTime' => $this->startTime?->format(DateTime::RFC3339_EXTENDED),
            'endTime' => $this->endTime?->format(DateTime::RFC3339_EXTENDED),
            'switchTime' => $this->switchTime?->format(DateTime::RFC3339_EXTENDED),
            'status' => $this->status,
            'manualTransferJobIds' => json_encode($this->manualTransferJobIds),
            'workspaceName' => $this->workspaceName,
            'accountId' => $this->accountId,
            'contentReleaseSize' => $this->contentReleaseSize,
        ];
    }

    public function withEndTime(DateTimeInterface $endTime): self
    {
        return new self(
            $this->prunnerJobId,
            $this->startTime,
            $endTime,
            $this->switchTime,
            $this->status,
            $this->manualTransferJobIds,
            $this->workspaceName,
            $this->accountId,
            $this->contentReleaseSize,
        );
    }

    public function withSwitchTime(DateTimeInterface $switchTime): self
    {
        return new self(
            $this->prunnerJobId,
            $this->startTime,
            $this->endTime,
            $switchTime,
            $this->status,
            $this->manualTransferJobIds,
            $this->workspaceName,
            $this->accountId,
            $this->contentReleaseSize,
        );
    }

    public function withStatus(NodeRenderingCompletionStatus $status): self
    {
        return new self(
            $this->prunnerJobId,
            $this->startTime,
            $this->endTime,
            $this->switchTime,
            $status,
            $this->manualTransferJobIds,
            $this->workspaceName,
            $this->accountId,
            $this->contentReleaseSize,
        );
    }

    public function withAdditionalManualTransferJobId(PrunnerJobId $prunnerJobId): self
    {
        $manualTransferIdArray = $this->manualTransferJobIds;
        $manualTransferIdArray[] = $prunnerJobId;
        return new self(
            $this->prunnerJobId,
            $this->startTime,
            $this->endTime,
            $this->switchTime,
            $this->status,
            $manualTransferIdArray,
            $this->workspaceName,
            $this->accountId,
            $this->contentReleaseSize,
        );
    }

    public function withContentReleaseSize(float $contentReleaseSize): self
    {
        return new self(
            $this->prunnerJobId,
            $this->startTime,
            $this->endTime,
            $this->switchTime,
            $this->status,
            $this->manualTransferJobIds,
            $this->workspaceName,
            $this->accountId,
            $contentReleaseSize,
        );
    }
}
