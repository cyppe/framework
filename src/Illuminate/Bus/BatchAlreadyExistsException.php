<?php

namespace Illuminate\Bus;

use RuntimeException;
use Throwable;

class BatchAlreadyExistsException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(public string $batchId, ?Throwable $previous = null)
    {
        parent::__construct("A batch with ID [{$batchId}] already exists.", 0, $previous);
    }
}
