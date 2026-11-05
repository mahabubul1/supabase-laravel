<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Runs an arbitrary Supabase operation on the queue.
 *
 * ```php
 * CallSupabase::dispatch(fn () => Supabase::table('events')->insert([
 *     'name' => 'user.registered',
 * ])->get());
 * ```
 *
 * Prefer this over calling Supabase inline in a request when the operation
 * is not needed to build the response (audit logging, fan-out writes,
 * non-critical side effects) so a slow or retried Supabase API call cannot
 * block or fail the user-facing request.
 */
class CallSupabase implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    protected SerializableClosure $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = new SerializableClosure($callback);
    }

    public function handle(): void
    {
        ($this->callback)();
    }
}
