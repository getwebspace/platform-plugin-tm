<?php declare(strict_types=1);

namespace Plugin\TradeMaster;

use App\Domain\AbstractSchedule;
use App\Domain\AbstractTask;
use App\Domain\Casts\Task\Status;
use App\Domain\Models\Task;
use Plugin\TradeMaster\Tasks\CatalogDownloadTask;

class TradeMasterSchedule extends AbstractSchedule
{
    public function run(): void
    {
        $pending = Task::query()
            ->where('action', CatalogDownloadTask::class)
            ->whereIn('status', [Status::QUEUE, Status::WORK])
            ->exists();

        if ($pending) {
            // the worker exits by itself when another one is running
            AbstractTask::worker(CatalogDownloadTask::class);

            return;
        }

        $task = new CatalogDownloadTask($this->container);
        $task->execute();

        AbstractTask::worker($task);
    }
}
