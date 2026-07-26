<?php

namespace Illuminate\Contracts\Queue;

interface ReleasableWithoutAttempt
{
    /**
     * Release the job back onto the queue without counting the delivery as an attempt.
     *
     * This does not reset the job's exception count or retry deadline.
     *
     * @param  int  $delay
     * @return void
     */
    public function releaseWithoutAttempt($delay = 0);
}
