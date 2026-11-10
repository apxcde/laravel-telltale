<?php

namespace Apxcde\LaravelTelltale\Filesystem;

use Illuminate\Contracts\Filesystem\Cloud;
use Apxcde\LaravelTelltale\Filesystem\Concerns\WrapsCloudFilesystem;

class FlareCloudFilesystem implements Cloud
{
    use WrapsCloudFilesystem;

    public function __construct(
        protected Cloud $filesystem,
        protected bool $trace,
        protected bool $report,
        protected int $maxReports
    ) {
    }
}
