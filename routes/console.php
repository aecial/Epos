<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// KDS completion is operational state, not audit history (see ClearKdsCompletionState) - swept
// nightly, off-peak. If a shift ever runs past this hour, in-progress ticks on a still-open
// ticket get cleared mid-service; confirm this time against real operating hours.
Schedule::command('kds:clear-completed')->dailyAt('03:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('make:service {name}', function (string $name) {
    $filesystem = new Filesystem;

    $name = trim($name);
    $appServicesPath = app_path('Services');

    // Normalize incoming name to support folder separators
    $normalized = str_replace('\\', '/', $name);
    $parts = explode('/', $normalized);
    $className = array_pop($parts);

    // Build target directory and namespace
    $subPath = $appServicesPath.(count($parts) ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts) : '');
    $namespace = 'App\\Services'.(count($parts) ? '\\'.implode('\\', $parts) : '');

    if (! $filesystem->isDirectory($subPath)) {
        $filesystem->makeDirectory($subPath, 0755, true);
    }

    $filePath = $subPath.DIRECTORY_SEPARATOR.$className.'.php';

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

    $this->info('Service created: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $filePath));
})->purpose('Create a new service class');
