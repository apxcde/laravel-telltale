<?php

namespace Apxcde\LaravelTelltale\Filesystem\Concerns;

use Apxcde\LaravelTelltale\Recorders\FilesystemRecorder\FilesystemRecorder;

trait WrapsCloudFilesystem
{
    use WrapsFileSystem;

    /**
     * Get the URL for the file at the given path.
     *
     * @param string $path
     *
     * @return string
     */
    public function url($path)
    {
        return $this->wrapCall(
            __FUNCTION__,
            func_get_args(),
            fn (FilesystemRecorder $recorder) => $recorder->recordUrl($path),
            fn ($return) => ['laravel.url' => $return]
        );
    }
}
