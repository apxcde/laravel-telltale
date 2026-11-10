<?php

namespace Apxcde\LaravelTelltale\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Apxcde\LaravelTelltale\Filesystem\Concerns\WrapsFileSystem;

class FlareFilesystem implements Filesystem
{
    use WrapsFileSystem;

    public function __construct(
        protected Filesystem $filesystem,
        protected bool $trace,
        protected bool $report,
        protected int $maxReports
    ) {
    }
}
