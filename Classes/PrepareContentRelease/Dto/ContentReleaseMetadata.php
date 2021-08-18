<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\PrepareContentRelease\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
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

    private function __construct(PrunnerJobId $prunnerJobId, ?\DateTimeInterface $startTime, ?\DateTimeInterface $endTime)
    {
        $this->prunnerJobId = $prunnerJobId;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }


    public static function create(PrunnerJobId $prunnerJobId, \DateTimeInterface $startTime): self
    {
        return new self($prunnerJobId, $startTime, null);
    }

    public static function fromJsonString($metadataEncoded): self
    {
        $tmp = json_decode($metadataEncoded, true);
        if (!is_array($tmp)) {
            throw new \Exception('ContentReleaseMetadata cannot be constructed from: ' . $metadataEncoded);
        }
        return new self(
            PrunnerJobId::fromString($tmp['prunnerJobId']),
            ($tmp['startTime'] !== null) ? \DateTimeImmutable::createFromFormat(\DateTime::RFC3339_EXTENDED, $tmp['startTime']) : null,
            ($tmp['endTime'] !== null) ? \DateTimeImmutable::createFromFormat(\DateTime::RFC3339_EXTENDED, $tmp['endTime']) : null
        );
    }


    public function jsonSerialize()
    {
        return [
            'prunnerJobId' => $this->prunnerJobId->getIdentifier(),
            'startTime' => $this->startTime ? $this->startTime->format(\DateTime::RFC3339_EXTENDED) : null,
            'endTime' => $this->endTime ? $this->endTime->format(\DateTime::RFC3339_EXTENDED) : null,
        ];
    }

    public function withEndTime(\DateTimeInterface $endTime): self
    {
        return new self($this->prunnerJobId, $this->startTime, $endTime);
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
}