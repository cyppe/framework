<?php

namespace Illuminate\Contracts\Cache;

interface RefreshableLock extends Lock
{
    /**
     * Attempt to refresh the lock for the given number of seconds.
     *
     * @param  int|null  $seconds
     * @return bool
     */
    public function refresh($seconds = null);
}
