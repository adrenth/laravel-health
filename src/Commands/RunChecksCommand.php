<?php

namespace Spatie\Health\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Exceptions\CheckDidNotComplete;
use Spatie\Health\Exceptions\CouldNotSaveResultsInStore;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Support\Result;

class RunChecksCommand extends Command
{
    public $signature = 'health:run-checks';

    public $description = 'Run all health checks';

    /** @var array<int, Exception>  */
    protected array $thrownExceptions = [];

    public function handle(): int
    {
        $this->comment('All done');

        $results = app(Health::class)
            ->registeredChecks()
            ->filter(fn(Check $check) => $check->shouldRun())
            ->map(fn(Check $check) => $this->runCheck($check));

        app(Health::class)
            ->resultStores()
            ->each(fn(ResultStore $store) => $store->save($results));

        if (count($this->thrownExceptions)) {
            foreach($this->thrownExceptions as $exception) {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public function runCheck(Check $check): Result
    {
        try {
            $result = $check->run();
        } catch (Exception $exception)
        {
            // return result with status failed
            $exception = CheckDidNotComplete::make($check, $exception);
            report($exception);

            $this->thrownExceptions[] = $exception;

            $result = $check->markAsCrashed();
        }

        return $result
            ->check($check)
            ->endedAt(now());
    }

    public function saveResults(Collection $results, ResultStore $store)
    {
        try {
            $store->save($results);
        } catch (Exception $exception) {
            $exception = CouldNotSaveResultsInStore::make($store, $exception);

            report($exception);
        }

    }

}