<?php

namespace Apxcde\LaravelTelltale\EntryPoint;

use Apxcde\LaravelTelltale\Support\UrlCensor;
use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\EntryPoint\EntryPointResolver;
use Spatie\FlareClient\Enums\EntryPointType;

/**
 * The entry point of a web request is its full URL, query string
 * included. This resolver censors the query string with the
 * `censor.body_fields` rules before the entry point is used.
 */
class CensoringEntryPointResolver extends EntryPointResolver
{
    public function __construct(protected UrlCensor $urlCensor)
    {
    }

    protected function resolve(): EntryPoint
    {
        $entryPoint = parent::resolve();

        if ($entryPoint->type === EntryPointType::Web) {
            $entryPoint->updateValue($this->urlCensor->url($entryPoint->value));
        }

        return $entryPoint;
    }
}
