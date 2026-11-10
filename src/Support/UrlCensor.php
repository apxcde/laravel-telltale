<?php

namespace Apxcde\LaravelTelltale\Support;

use Spatie\FlareClient\Support\Redactor;

/**
 * Censors the values of query string parameters with the same rules as
 * the request body (`censor.body_fields`), so a value hidden in the body
 * is not sent readable in the URL next to it.
 */
class UrlCensor
{
    public function __construct(protected Redactor $redactor)
    {
    }

    /**
     * Censor the query string of a URL. A URL without censored parameters
     * is returned exactly as given.
     */
    public function url(string $url): string
    {
        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $beforeQuery = substr($url, 0, $queryStart);
        $query = substr($url, $queryStart + 1);
        $fragment = '';

        $fragmentStart = strpos($query, '#');

        if ($fragmentStart !== false) {
            $fragment = substr($query, $fragmentStart);
            $query = substr($query, 0, $fragmentStart);
        }

        return "{$beforeQuery}?{$this->query($query)}{$fragment}";
    }

    /**
     * Censor a query string (without the leading `?`). A query string
     * without censored parameters is returned exactly as given.
     */
    public function query(string $query): string
    {
        if ($query === '') {
            return $query;
        }

        parse_str($query, $parameters);

        $censored = $this->redactor->censorBody($parameters);

        if ($censored === $parameters) {
            return $query;
        }

        // Keep the censor marker readable instead of percent-encoded.
        return (string) preg_replace(
            '/%3CCENSORED%3A([A-Za-z_]+)%3E/',
            '<CENSORED:$1>',
            http_build_query($censored),
        );
    }
}
