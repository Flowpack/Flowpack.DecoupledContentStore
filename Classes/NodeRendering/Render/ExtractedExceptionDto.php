<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

final readonly class ExtractedExceptionDto
{
    private string $message;

    private string $stackTrace;

    private string $referenceCode;

    public function __construct(string $message, string $stackTrace, string $referenceCode)
    {
        $this->message = $message;
        $this->stackTrace = $stackTrace;
        $this->referenceCode = $referenceCode;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getStackTrace(): string
    {
        return $this->stackTrace;
    }

    /**
     * @return string
     */
    public function getReferenceCode(): string
    {
        return $this->referenceCode;
    }

    public function __toString(): string
    {
        return (
            $this->getMessage()
            . (!empty($this->getStackTrace()) ? "\n{$this->getStackTrace()}" : '')
            . (!empty($this->getReferenceCode()) ? "\n(reference code {$this->getReferenceCode()})" : '')
        );
    }
}
