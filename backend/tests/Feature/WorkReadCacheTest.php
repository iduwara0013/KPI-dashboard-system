<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkReadCache;
use Illuminate\Http\Request;
use Tests\TestCase;

class WorkReadCacheTest extends TestCase
{
    public function test_reads_are_reused_only_within_the_same_get_request(): void
    {
        $calls = 0;
        $read = function () use (&$calls): int {
            return ++$calls;
        };
        $this->app->instance('request', Request::create('/api/delivery/workspace', 'GET'));
        $this->assertSame(1, WorkReadCache::remember('scope', $read));
        $this->assertSame(1, WorkReadCache::remember('scope', $read));
        $this->app->instance('request', Request::create('/api/delivery/workspace', 'GET'));
        $this->assertSame(2, WorkReadCache::remember('scope', $read));
        $this->app->instance('request', Request::create('/api/delivery/tasks/1/progress', 'PUT'));
        $this->assertSame(3, WorkReadCache::remember('scope', $read));
        $this->assertSame(4, WorkReadCache::remember('scope', $read));
    }
}
