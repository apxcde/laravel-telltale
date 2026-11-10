<?php

namespace Apxcde\LaravelTelltale\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;
use Apxcde\LaravelTelltale\Filesystem\Concerns\WrapsFilesystemAdapter;

class FlareFilesystemAdapter extends FilesystemAdapter
{
    use WrapsFilesystemAdapter;

    public function __construct(
        protected FilesystemAdapter $filesystem,
        protected bool $trace,
        protected bool $report,
        protected int $maxReports
    ) {
        parent::__construct($filesystem->getDriver(), $filesystem->getAdapter(), $filesystem->getConfig());
    }
}
