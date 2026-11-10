<?php

namespace Apxcde\LaravelTelltale\Recorders\ViewRecorder;

use Illuminate\View\DynamicComponent;
use Throwable;

class ComponentFinder extends DynamicComponent
{
    public function __construct()
    {
    }

    public function resolveClassForComponent(string $component): ?string
    {
        try {
            return static::$componentClasses[$component] ??= $this->compiler()->componentClass($component);
        } catch (Throwable) {
            return null;
        }
    }
}
