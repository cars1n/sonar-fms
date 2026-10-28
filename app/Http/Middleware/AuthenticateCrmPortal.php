<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticateCrmPortal
{
    public function handle(Request $request, Closure $next): mixed
    {
        $configuredToken = (string) config('services.crm_portal.token');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $tenant = Tenant::current();
        abort_unless($tenant && $tenant->id === config('services.crm_portal.tenant_id'), 403);
        abort_unless(in_array($request->integer('school_sis_id'), config('services.crm_portal.school_sis_ids', []), true), 403);

        return $next($request);
    }
}
