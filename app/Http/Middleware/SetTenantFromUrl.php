<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;

class SetTenantFromUrl
{
    public function handle($request, Closure $next)
    {
        // Extract the tenant slug from the URL (e.g., example.com/{tenant}/api)
        $tenantSlug = $request->route('tenant_slug');

        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'This workspace is not registered on our platform'], 404);
        }

        // Set the tenant globally (e.g., session, request, or service container)
        session(['tenant_id' => $tenant->id]);

        return $next($request);
    }
}
