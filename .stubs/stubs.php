<?php

namespace Illuminate\Database\Query
{

    use DateInterval;
    use DateTimeInterface;

    class Builder
    {
        /**
         * Caches the underlying query results.
         *
         * @param  int|\DateTimeInterface|\DateInterval  $ttl
         * @param  string  $key
         * @param  string|null  $store
         * @param  int  $wait
         * @return $this
         */
        public function cache(int|DateTimeInterface|DateInterval $ttl = 60, string $key = '', string $store = null, int $wait = 0): static
        {
            //
        }
    }
}

namespace Illuminate\Database\Eloquent
{

    use DateInterval;
    use DateTimeInterface;

    class Builder
    {
        /**
         * Caches the underlying query results.
         *
         * @param  int|\DateTimeInterface|\DateInterval  $ttl
         * @param  string  $key
         * @param  string|null  $store
         * @param  int  $wait
         * @return $this
         */
        public function cache(int|DateTimeInterface|DateInterval $ttl = 60, string $key = '', string $store = null, int $wait = 0): static
        {
            //
        }
    }
}
