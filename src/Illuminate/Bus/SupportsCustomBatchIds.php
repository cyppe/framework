<?php

namespace Illuminate\Bus;

/**
 * Indicates that a batch repository honors custom batch IDs.
 *
 * Implementations must not overwrite an existing batch when storing a given ID
 * and must throw a BatchAlreadyExistsException when that ID already exists.
 */
interface SupportsCustomBatchIds
{
    //
}
