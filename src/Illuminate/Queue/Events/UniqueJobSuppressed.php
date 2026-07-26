<?php

namespace Illuminate\Queue\Events;

class UniqueJobSuppressed
{
    /**
     * Create a new event instance.
     *
     * @param  mixed  $job  The job whose unique lock could not be acquired.
     * @param  string  $key  The unique lock key.
     */
    public function __construct(
        public $job,
        public $key,
    ) {
    }
}
