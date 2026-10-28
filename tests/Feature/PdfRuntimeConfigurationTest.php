<?php

namespace Tests\Feature;

use App\Factories\PdfBrowserFactory;
use Tests\TestCase;

class PdfRuntimeConfigurationTest extends TestCase
{
    public function test_the_configured_browser_overrides_the_process_default(): void
    {
        $default = getenv('PUPPETEER_EXECUTABLE_PATH');

        config()->set('services.node.modules', '/usr/local/lib/node_modules');
        config()->set('services.node.chrome', '/usr/bin/chromium');
        putenv('PUPPETEER_EXECUTABLE_PATH=/does-not-exist/chrome');

        try {
            $pdf = PdfBrowserFactory::make('<html><body>PDF runtime verification</body></html>')
                ->noSandbox()
                ->pdf();

            $this->assertStringStartsWith('%PDF-', $pdf);
        } finally {
            putenv($default === false ? 'PUPPETEER_EXECUTABLE_PATH' : 'PUPPETEER_EXECUTABLE_PATH='.$default);
        }
    }
}
