<?php

namespace Illuminate\Database\Query {

    use DateInterval;
    use DateTimeInterface;

    class Builder
    {
        /**
         * Caches the underlying query results.
         *
         * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
         * @param  string  $key
         * @param  string|null  $store
         * @param  int  $wait
         * @return static
         */
        public function cache(
            DateTimeInterface|DateInterval|int|null $ttl = null,
            string $key = '',
            string $store = null,
            int $wait = 0
        ): static {
            //
        }
    }
}
