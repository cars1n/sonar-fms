<?php

namespace App\Tasks;

use App\Http\Resources\TenantResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class ChangeConfigTask implements SwitchTenantTask
{
    private string $originalUrl;

    public function makeCurrent(IsTenant $tenant): void
    {
        $this->originalUrl = config('app.url');

        Config::set('app.url', app()->isLocal()
            ? request()->root()
            : "https://{$tenant->domain}");
        Config::set('powerschool.server_address', $tenant->ps_url);
        Config::set('powerschool.client_id', $tenant->ps_client_id);
        Config::set('powerschool.client_secret', $tenant->ps_secret);

        if (! config('app.cloud')) {
            Config::set('mail.default', $tenant->smtp_host ? 'smtp' : 'log');
            Config::set('mail.mailers.smtp.host', $tenant->smtp_host);
            Config::set('mail.mailers.smtp.port', $tenant->smtp_port);
            Config::set('mail.mailers.smtp.username', $tenant->smtp_username);
            Config::set('mail.mailers.smtp.password', $tenant->smtp_password);
            Config::set('mail.from.name', $tenant->smtp_from_name);
            Config::set('mail.from.address', $tenant->smtp_from_address);
            Config::set('mail.mailers.smtp.encryption', $tenant->smtp_encryption);
        }

        URL::forceRootUrl(config('app.url'));

        Inertia::share('tenant', function () use ($tenant) {
            return new TenantResource($tenant);
        });

        Inertia::share('psEnabled', function () use ($tenant) {
            return $tenant->ps_url &&
                $tenant->ps_client_id &&
                $tenant->ps_secret;
        });
    }

    public function forgetCurrent(): void
    {
        Config::set('app.url', $this->originalUrl);
        Config::set('powerschool.server_address');
        Config::set('powerschool.client_id');
        Config::set('powerschool.client_secret');

        URL::forceRootUrl($this->originalUrl);
    }
}
