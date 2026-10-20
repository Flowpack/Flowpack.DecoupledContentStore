<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Exception;

use Exception;
use Flowpack\DecoupledContentStore\Exception as DecoupledContentStoreException;

final class RenderingException extends DecoupledContentStoreException
{
    public function __construct(
        string $message,
        public readonly string $nodeUri,
        int $code = 0,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
