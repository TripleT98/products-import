<?php

declare(strict_types=1);

namespace App\Queue;

use App\Import\Job\ImportJobService;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class ImportPublisher
{
    public function __construct(private ImportJobService $jobs, private UploadStorage $uploads, private MessageBusInterface $bus)
    {
    }

    public function enqueueLocal(string $source, int $ownerId): string
    {
        $name = $this->uploads->localCopy($source);
        return $this->enqueueSaved($name, $ownerId);
    }

    /** The HTTP boundary has already saved and validated this private copy. */
    public function enqueueSaved(string $name, int $ownerId): string
    {
        $this->uploads->readable($name);
        try {
            $id = $this->jobs->create($ownerId, $name);
        } catch (Throwable $error) {
            $this->uploads->removeOwnedCopy($name);
            throw $error;
        }
        try {
            $this->bus->dispatch(new ImportMessage($id));
        } catch (Throwable $error) {
            try {
                $this->jobs->failQueuedPublication($id);
            } catch (Throwable) { /* Keep the durable job and report failure. */
            }
            throw new EnqueueFailed($id, $error);
        }
        return $id;
    }
}
