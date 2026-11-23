<?php

namespace Apxcde\LaravelTelltale\Support;

use Monolog\Level;
use Monolog\LogRecord;
use Spatie\FlareClient\Logger;
use Spatie\FlareClient\Support\FlareLogHandler as BaseFlareLogHandler;
use Spatie\FlareClient\Support\SeverityMapper;

class FlareLogHandler extends BaseFlareLogHandler
{
    protected CurrentUser $currentUser;

    public function __construct(
        protected Logger $logger,
        Level $level = self::DEFAULT_MONOLOG_LEVEL,
        bool $bubble = true,
        ?CurrentUser $currentUser = null,
    ) {
        parent::__construct($this->logger, $level, $bubble);

        $this->currentUser = $currentUser ?? app(CurrentUser::class);
    }

    /**
     * The same record the client's handler sends, with the id of the user behind it. Only
     * the id is added: a request can write many records, and its trace already names the user.
     */
    protected function write(LogRecord $record): void
    {
        if (array_key_exists('exception', $record->context)) {
            return;
        }

        $attributes = ['log.context' => $record->context];

        $userId = $this->currentUser->id();

        if ($userId !== null) {
            $attributes['user.id'] = $userId;
        }

        $this->logger->log(
            timestampUnixNano: $record->datetime,
            body: $record->message,
            severityText: strtolower($record->level->getName()),
            severityNumber: SeverityMapper::fromSyslog($record->level->getName()),
            attributes: $attributes,
        );
    }
}
