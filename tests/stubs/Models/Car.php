<?php

namespace Apxcde\LaravelTelltale\Tests\stubs\Models;

class Car
{
    public $brand;
    public $color;

    public function __construct($brand, $color)
    {
        $this->brand = $brand;
        $this->color = $color;
    }
}
