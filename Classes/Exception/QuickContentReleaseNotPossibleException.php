<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Exception;

use Flowpack\DecoupledContentStore\Exception;

/**
 * The message of this exception is written for an editor: it is shown as-is when a quick release cannot be scheduled.
 */
class QuickContentReleaseNotPossibleException extends Exception {}
