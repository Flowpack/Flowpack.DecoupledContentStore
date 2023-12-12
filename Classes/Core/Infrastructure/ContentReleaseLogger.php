<?php

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Neos\Flow\Cli\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ContentReleaseLogger
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var ContentReleaseIdentifier
     */
    protected $contentReleaseIdentifier;

    /**
     * @var string
     */
    protected $logPrefix = '';

    protected function __construct(OutputInterface $output, ContentReleaseIdentifier $contentReleaseIdentifier, ?RendererIdentifier $rendererIdentifier)
    {
        $this->output = $output;
        $this->contentReleaseIdentifier = $contentReleaseIdentifier;
        $this->rendererIdentifier = $rendererIdentifier;
        $this->logPrefix = '';

        if ($this->rendererIdentifier !== null) {
            $this->logPrefix = '[Renderer ' . $this->rendererIdentifier->string() . '] ';
        }
    }


    public static function fromConsoleOutput(ConsoleOutput $output, ContentReleaseIdentifier $contentReleaseIdentifier): self
    {
        return new static($output->getOutput(), $contentReleaseIdentifier, null);
    }

    public static function fromSymfonyOutput(OutputInterface $output, ContentReleaseIdentifier $contentReleaseIdentifier): self
    {
        return new static($output, $contentReleaseIdentifier, null);
    }

    public function debug($message, array $additionalPayload = []): void
    {
        $this->logToOutput($message, $additionalPayload);
    }

    public function info($message, array $additionalPayload = []): void
    {
        $this->logToOutput($message, $additionalPayload);
    }

    public function warn($message, array $additionalPayload = []): void
    {
        $this->logToOutput($message, $additionalPayload);
    }

    public function error($message, array $additionalPayload = []): void
    {
        $this->logToOutput($message, $additionalPayload);
    }

    protected function logToOutput($message, array $additionalPayload = []): void
    {
        $formattedPayload = $additionalPayload ? json_encode($additionalPayload) : '';
        $this->output->writeln($this->logPrefix . $message . $formattedPayload);
    }

    public function logException(\Exception $exception, string $message, array $additionalPayload)
    {
        $this->output->writeln($this->logPrefix . $message . "\n\n" . $exception->getMessage() . "\n\n" . $exception->getTraceAsString() . "\n\n" . json_encode($additionalPayload));
    }

    public function withRenderer(RendererIdentifier $rendererIdentifier): self
    {
        return new ContentReleaseLogger($this->output, $this->contentReleaseIdentifier, $rendererIdentifier);
    }
}
