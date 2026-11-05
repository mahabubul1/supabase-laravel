<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies inbound Supabase Database Webhooks (pg_net triggers calling into
 * this app).
 *
 * Supabase does not HMAC-sign these requests like Stripe does — the
 * supported pattern is a shared secret sent as a custom HTTP header, set
 * under the webhook's "HTTP Headers" in the Supabase dashboard. This
 * middleware compares that header against SUPABASE_WEBHOOK_SECRET using a
 * timing-safe comparison.
 */
class VerifySupabaseWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('supabase.webhooks.secret');
        $header = config('supabase.webhooks.header', 'X-Supabase-Signature');

        if (! is_string($secret) || $secret === '') {
            abort(500, 'SUPABASE_WEBHOOK_SECRET is not configured.');
        }

        $provided = (string) $request->header($header, '');

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
