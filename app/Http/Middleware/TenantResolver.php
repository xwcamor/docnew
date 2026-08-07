<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Tenant;

class TenantResolver
{
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost(); // Example: empresa1.blog.test
        $parts = explode('.', $host); // ["empresa1","blog","test"]

        // Get the first part as subdomain (empresa1)
        $subdomain = $parts[0];

        // Skip if it is the base domain "blog"
        if ($subdomain !== 'blog') {
            $tenant = Tenant::where('slug', $subdomain)->first();

            // Store tenant_id in session if it exists
            if ($tenant) {
                session(['tenant_id' => $tenant->id]);
            }
        }

        return $next($request);
    }
}