<?php

namespace Illuminate\Queue\Events;

class UniqueJobSuppressed
{
    /**
     * Create a new event instance.
     *
     * @param  mixed  $job
     * @param  string  $key
     */
    public function __construct(
        public $job,
        public $key,
    ) {
    }
}
