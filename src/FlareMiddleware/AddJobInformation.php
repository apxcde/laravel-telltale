<?php

namespace Apxcde\LaravelTelltale\FlareMiddleware;

use Apxcde\LaravelTelltale\Support\CurrentUser;
use Closure;
use Spatie\FlareClient\FlareMiddleware\AddJobInformation as BaseAddJobInformation;
use Spatie\FlareClient\ReportFactory;

class AddJobInformation extends BaseAddJobInformation
{
    public function __construct(
        protected CurrentUser $currentUser,
    ) {
    }

    /**
     * Name the user who dispatched the job on a report that comes from it.
     *
     * A worker reports a job's exception after the job has ended, so the id is read from the
     * span the job left behind. A report made while the job still runs takes the id the job
     * carries.
     */
    public function handle(ReportFactory $report, Closure $next): ReportFactory
    {
        $userId = CurrentUser::normalizeId(static::$latestJob?->attributes['user.id'] ?? null)
            ?? $this->currentUser->carried();

        if ($userId !== null) {
            $report->addAttributes(['user.id' => $userId]);
        }

        return parent::handle($report, $next);
    }
}
