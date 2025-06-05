<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;

class EnsureTenantHasActivePlan
{
    public function handle(Request $request, Closure $next)
    {
        $tenantSlug = $request->route('tenant_slug');

        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if (!$tenant) {
            abort(403, 'Tenant not identified.');
        }

        $subscription = $tenant->subscription; 

        // Check if subscription exists and is currently active
        if (
            !$subscription ||
            !$subscription->starts_at ||
            !$subscription->ends_at ||
            now()->lt($subscription->starts_at) || // Too early
            now()->gt($subscription->ends_at)     // Expired
        ) {
            // Revoke the token (for Sanctum)
            // if ($request->user()) {
            //     $request->user()->currentAccessToken()?->delete();
            // }
            
            return response()->json(['message'=>'Your subscription is inactive or expired.'], 403);
        }

        return $next($request);
    }
}
