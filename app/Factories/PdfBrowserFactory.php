<?php

namespace App\Factories;

use Spatie\Browsershot\Browsershot;

class PdfBrowserFactory
{
    public static function make(string $html): Browsershot
    {
        $browser = Browsershot::html($html)
            ->setNodeBinary(config('services.node.binary'))
            ->setNpmBinary(config('services.node.npm'));

        if ($modules = config('services.node.modules')) {
            $browser->setNodeModulePath($modules);
        }

        if ($chrome = config('services.node.chrome')) {
            $browser->setChromePath($chrome);
        }

        return $browser;
    }
}
