<?php

use Illuminate\Database\QueryException;
use Apxcde\LaravelTelltale\Facades\Telltale;
use Apxcde\LaravelTelltale\TelltaleConfig;

it('will add query information with a query exception', function () {
    setupFlare();

    $sql = 'select * from users where emai = "ruben@spatie.be"';

    $report = Telltale::report(new QueryException(
        'default',
        '' . $sql . '',
        [],
        new Exception()
    ))->toArray();

    $attributes = $report['attributes'];

    $this->assertArrayHasKey('flare.exception.db_statement', $attributes);
    expect($attributes['flare.exception.db_statement'])->toBe($sql);
});

it('takes the bound values out of the query exception message unless the application opts in', function () {
    setupFlare();

    $exception = new QueryException(
        'sqlite',
        'select * from "contacts" where "phone" = ?',
        ['+15550001111'],
        new Exception('SQLSTATE[HY000]: boom'),
    );

    $originalMessage = $exception->getMessage();

    $report = Telltale::report($exception)->toArray();

    expect($report['message'])->toBe(
        'SQLSTATE[HY000]: boom (Connection: sqlite, SQL: select * from "contacts" where "phone" = ?)'
    );
    expect($exception->getMessage())->toBe($originalMessage)->toContain('+15550001111');
});

it('rewrites the query exception message with quoted string bindings', function () {
    setupFlare(fn (TelltaleConfig $config) => $config->collectQueries(includeBindings: true));

    $exception = new QueryException(
        'sqlite',
        'delete from "users" where "ref_id" in (?, ?)',
        ['019e4430-4bce-73fa-a8fb-6fedc84e6fd2', '019e4430-4bce-73fa-a8fb-6fedc8f854f2'],
        new Exception('SQLSTATE[HY000]: General error: 1020 Record has changed'),
    );

    $originalMessage = $exception->getMessage();

    $report = Telltale::report($exception)->toArray();

    expect($report['message'])->toBe(
        'SQLSTATE[HY000]: General error: 1020 Record has changed (Connection: sqlite, SQL: delete from "users" where "ref_id" in (\'019e4430-4bce-73fa-a8fb-6fedc84e6fd2\', \'019e4430-4bce-73fa-a8fb-6fedc8f854f2\'))'
    );
    expect($exception->getMessage())->toBe($originalMessage);
});

it('correctly substitutes every supported binding type', function () {
    setupFlare(fn (TelltaleConfig $config) => $config->collectQueries(includeBindings: true));

    $sql = 'select * from "t" where "a" = ? and "b" = ? and "c" = ? and "d" = ? and "e" = ? and "f" = ? and "g" is ? and "h" = ? and "i" = ?';
    $bindings = ['hello', "O'Brien", 42, 3.14, true, false, null, 0, ''];

    $exception = new QueryException(
        'sqlite',
        $sql,
        $bindings,
        new Exception('SQLSTATE[HY000]: boom'),
    );

    $report = Telltale::report($exception)->toArray();

    expect($report['message'])->toBe(
        'SQLSTATE[HY000]: boom (Connection: sqlite, SQL: select * from "t" where "a" = \'hello\' and "b" = \'O\'\'Brien\' and "c" = 42 and "d" = 3.14 and "e" = 1 and "f" = 0 and "g" is NULL and "h" = 0 and "i" = \'\')'
    );
});

it('leaves the report message untouched when the format is unexpected', function () {
    setupFlare();

    $exception = new QueryException(
        'sqlite',
        'select * from "users" where "id" = ?',
        [1],
        new Exception('SQLSTATE[HY000]: boom'),
    );

    (new ReflectionProperty(Exception::class, 'message'))
        ->setValue($exception, 'something completely different without the expected suffix');

    $report = Telltale::report($exception)->toArray();

    expect($report['message'])->toBe('something completely different without the expected suffix');
});

it('wont add query information without a query exception', function () {
    setupFlare();

    $report = Telltale::report(new Exception())->toArray();

    $attributes = $report['attributes'];

    $this->assertArrayNotHasKey('flare.exception.db_statement', $attributes);
});

it('will add user context when provided on a custom exception', function () {
    setupFlare();

    $report = Telltale::report(new class extends Exception {
        public function context()
        {
            return [
                'hello' => 'world',
            ];
        }
    })->toArray();

    $context = $report['attributes']['context.exception'];

    expect($context['hello'])->toBe('world');
});

it('censors user context with the body fields censor list', function () {
    setupFlare(fn (TelltaleConfig $config) => $config->censorBodyFields('from', 'to'));

    $report = Telltale::report(new class extends Exception {
        public function context()
        {
            return [
                'From' => '+15551234567',
                'message_sid' => 'SM123',
                'webhook' => ['to' => '+15557654321'],
            ];
        }
    })->toArray();

    expect($report['attributes']['context.exception'])->toBe([
        'From' => '<CENSORED:string>',
        'message_sid' => 'SM123',
        'webhook' => ['to' => '<CENSORED:string>'],
    ]);
});

it('will only add arrays as user provided context', function () {
    setupFlare();

    $report = Telltale::report(new class extends Exception {
        public function context()
        {
            return (object) [
                'hello' => 'world',
            ];
        }
    })->toArray();

    expect($report['attributes'])->not()->toHaveKey('context');
});
