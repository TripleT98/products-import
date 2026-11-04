<?php

declare(strict_types=1);

namespace App\Queue;

use App\Image\ImageDownloader;
use App\Image\ImageFileCleaner;
use App\Image\ImageStorage;
use App\Image\ImageUrlPolicy;
use App\Import\Job\ImportJobService;
use App\Import\ProductWriter;
use App\Import\RowImportService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

final class ImportQueue
{
    public readonly AmqpTransport $imports;
    public readonly AmqpTransport $failed;
    public readonly MessageBus $bus;
    public readonly ImportHandler $handler;
    public readonly ImportJobService $jobs;
    public readonly ImportPublisher $publisher;
    private int $retryDelay;

    public function __construct(?ImageUrlPolicy $testPolicy = null)
    {
        $lease = self::positive('IMPORT_LEASE_SECONDS', 60, 3600);
        $this->retryDelay = self::positive('IMPORT_RETRY_DELAY_MS', 1000, 60000);
        $prefix = getenv('IMPORT_QUEUE_PREFIX') ?: 'product_import';
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,60}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid queue prefix.');
        }
        $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
        $port = self::positive('RABBITMQ_PORT', 5672, 65535);
        $user = rawurlencode(getenv('RABBITMQ_DEFAULT_USER') ?: '');
        $pass = rawurlencode(getenv('RABBITMQ_DEFAULT_PASS') ?: '');
        $vhost = rawurlencode(getenv('RABBITMQ_DEFAULT_VHOST') ?: '/');
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host)) {
            throw new \InvalidArgumentException('Invalid broker host.');
        }
        $dsn = 'amqp://' . $user . ':' . $pass . '@' . $host . ':' . $port . '/' . $vhost;
        $serializer = new ImportMessageSerializer();
        $make = static fn (string $name): AmqpTransport => new AmqpTransport(Connection::fromDsn($dsn, [
            'connect_timeout' => 2, 'read_timeout' => 2, 'write_timeout' => 2, 'confirm_timeout' => 5, 'heartbeat' => 0,
            'exchange' => ['name' => $name, 'type' => 'direct', 'default_publish_routing_key' => $name],
            'queues' => [$name => ['binding_keys' => [$name]]],
            'delay' => ['exchange_name' => $name . '.delays'],
        ]), $serializer);
        $this->imports = $make($prefix . '.imports');
        $this->failed = $make($prefix . '.failed');
        $this->jobs = new ImportJobService(leaseSeconds: $lease);
        $storage = ImageStorage::fromEnvironment();
        $uploads = UploadStorage::fromEnvironment();
        $this->handler = new ImportHandler($this->jobs, $uploads, new RowImportService(new ImageDownloader($storage, policy: $testPolicy), new ProductWriter(), new ImageFileCleaner($storage)), $lease);
        $this->bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ImportMessage::class => ['imports']], new QueueServices(['imports' => $this->imports]))),
            new HandleMessageMiddleware(new HandlersLocator([ImportMessage::class => [$this->handler]])),
        ]);
        $this->publisher = new ImportPublisher($this->jobs, $uploads, $this->bus);
    }

    public function run(int $timeLimit = 3600, int $messageLimit = 0): void
    {
        $this->imports->setup();
        $this->failed->setup();
        $events = new EventDispatcher();
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new QueueServices(['imports' => $this->imports]),
            new QueueServices(['imports' => new MultiplierRetryStrategy(3, $this->retryDelay, 2, 60000, 0)]),
            eventDispatcher: $events
        ));
        $events->addSubscriber(new SendFailedMessageToFailureTransportListener(new QueueServices(['imports' => $this->failed]), failureTransportsByName: ['imports' => 'failed']));
        $events->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event): void {
            echo json_encode(['event' => 'import.retry', 'jobId' => $event->getEnvelope()->getMessage()->jobId], JSON_THROW_ON_ERROR) . "\n";
        });
        $events->addListener(WorkerMessageFailedEvent::class, function (WorkerMessageFailedEvent $event): void {
            if (!$event->willRetry() && $event->getEnvelope()->getMessage() instanceof ImportMessage) {
                $this->handler->exhausted($event->getEnvelope()->getMessage());
                echo json_encode(['event' => 'import.failed_transport', 'jobId' => $event->getEnvelope()->getMessage()->jobId], JSON_THROW_ON_ERROR) . "\n";
            }
        }, -150);
        $events->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit));
        if ($messageLimit > 0) {
            $events->addSubscriber(new StopWorkerOnMessageLimitListener($messageLimit));
        }
        $worker = new Worker(['imports' => $this->imports], $this->bus, $events);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static fn () => $worker->stop());
        pcntl_signal(SIGINT, static fn () => $worker->stop());
        echo json_encode(['event' => 'import.worker_started'], JSON_THROW_ON_ERROR) . "\n";
        try {
            $worker->run(['sleep' => 200000]);
        } finally {
            $this->imports->close();
            $this->failed->close();
        }
        echo json_encode(['event' => 'import.worker_stopped'], JSON_THROW_ON_ERROR) . "\n";
    }

    public function stats(): array
    {
        $this->imports->setup();
        $this->failed->setup();
        return ['imports' => $this->imports->getMessageCount(), 'failed' => $this->failed->getMessageCount()];
    }

    private static function positive(string $name, int $default, int $maximum): int
    {
        $value = getenv($name);
        if ($value !== false && (!ctype_digit($value) || strlen($value) > 9 || (int) $value < 1 || (int) $value > $maximum)) {
            throw new \InvalidArgumentException('Invalid queue configuration.');
        }
        return $value === false ? $default : (int) $value;
    }
}
