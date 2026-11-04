<?php

declare(strict_types=1);

namespace App\Queue;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

/** Versioned JSON only. Never deserialize PHP objects or exception traces from the broker. */
final class ImportMessageSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $body = json_decode($encodedEnvelope['body'], true, 4, JSON_THROW_ON_ERROR);
            $headers = $encodedEnvelope['headers'] ?? [];
            if (($headers['type'] ?? '') !== 'import-job.v1' || !is_array($body) || array_keys($body) !== ['jobId'] || !is_string($body['jobId'])) {
                throw new \InvalidArgumentException('Unsupported import envelope.');
            }
            $retry = $this->retryCount($headers['retry-count'] ?? '0');
            $envelope = new Envelope(new ImportMessage($body['jobId']), [new RedeliveryStamp((int) $retry)]);
            if (($headers['failed-from'] ?? '') === 'imports') {
                $envelope = $envelope->with(new SentToFailureTransportStamp('imports'));
            }
            return $envelope;
        } catch (Throwable $error) {
            throw new MessageDecodingFailedException('Invalid import message.', 0, $error);
        }
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof ImportMessage) {
            throw new \InvalidArgumentException('Only import messages can use this transport.');
        }
        $headers = ['type' => 'import-job.v1', 'retry-count' => (string) RedeliveryStamp::getRetryCountFromEnvelope($envelope)];
        if ($envelope->last(SentToFailureTransportStamp::class) !== null) {
            $headers['failed-from'] = 'imports';
        }
        return ['body' => json_encode(['jobId' => $message->jobId], JSON_THROW_ON_ERROR), 'headers' => $headers];
    }

    private function retryCount(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 3 || (int) $value > 100) {
            throw new \InvalidArgumentException('Invalid retry count.');
        }
        return (int) $value;
    }

}
