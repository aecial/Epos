<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('make:service {name}', function (string $name) {
    $filesystem = new \Illuminate\Filesystem\Filesystem();

    $name = trim($name);
    $appServicesPath = app_path('Services');

    // Normalize incoming name to support folder separators
    $normalized = str_replace('\\', '/', $name);
    $parts = explode('/', $normalized);
    $className = array_pop($parts);

    // Build target directory and namespace
    $subPath = $appServicesPath . (count($parts) ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) : '');
    $namespace = 'App\\Services' . (count($parts) ? '\\' . implode('\\', $parts) : '');

    if (! $filesystem->isDirectory($subPath)) {
        $filesystem->makeDirectory($subPath, 0755, true);
    }

    $filePath = $subPath . DIRECTORY_SEPARATOR . $className . '.php';

    if ($filesystem->exists($filePath)) {
        $this->error("Service already exists: {$filePath}");
        return 1;
    }

    $stub = <<<'PHP'
<?php

namespace {{namespace}};

class {{class}}
{
    public function __construct()
    {
        //
    }
}

PHP;

    $content = str_replace(['{{namespace}}', '{{class}}'], [$namespace, $className], $stub);

    $filesystem->put($filePath, $content);

    $this->info('Service created: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filePath));
})->purpose('Create a new service class');
