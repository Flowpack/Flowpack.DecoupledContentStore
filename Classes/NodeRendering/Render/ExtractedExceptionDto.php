<?php
namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

class ExtractedExceptionDto
{

    /**
     * @var string
     */
    protected $message = '';

    /**
     * @var string
     */
    protected $stackTrace = '';

    /**
     * @var string
     */
    protected $referenceCode = '';

    public function __construct($message, $stackTrace, $referenceCode)
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

    public function __toString()
    {
        return $this->getMessage() . (!empty($this->getStackTrace()) ? "\n{$this->getStackTrace()}" : '') . (!empty($this->getReferenceCode()) ? "\n(reference code {$this->getReferenceCode()})" : '');
    }

}
