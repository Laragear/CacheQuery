<?php

namespace Laragear\CacheQuery\Exceptions;

use Exception;

/**
 * This exception should be only for stopping a query from real execution.
 *
 * @internal
 */
class CacheKeyFound extends Exception
{
    /**
     * Capture the data.
     *
     * @param  mixed  $results
     */
    public function __construct(public mixed $results)
    {
        //
    }
}
