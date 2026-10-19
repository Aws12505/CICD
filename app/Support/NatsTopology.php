<?php

namespace App\Support;

use App\Models\Service;
use App\Models\Stack;

/**
 * The naming convention every service already follows, written down once.
 *
 *   stream    HIRING_EVENTS            subjects  hiring.v1.>
 *   testing   HIRING_TESTING_EVENTS    subjects  hiring.testing.v1.>
 *   durable   OPERATIONS_HIRING_CONSUMER
 *   testing   OPERATIONS_HIRING_TESTING_CONSUMER
 *
 * The services pick the testing names themselves when DEV_MODE=1, so both
 * tiers share one NATS server without ever reading each other's events. The
 * catalog only records base names ("HIRING") and live durable names; this
 * class derives the rest, so nobody has to type twenty-odd names twice.
 */
final class NatsTopology
{
    public static function stream(string $base, string $tier): string
    {
        $base = strtoupper($base);

        return $tier === Stack::TIER_LIVE ? "{$base}_EVENTS" : "{$base}_TESTING_EVENTS";
    }

    public static function subject(string $base, string $tier): string
    {
        $base = strtolower($base);

        return $tier === Stack::TIER_LIVE ? "{$base}.v1.>" : "{$base}.testing.v1.>";
    }

    public static function durable(string $liveDurable, string $tier): string
    {
        return $tier === Stack::TIER_LIVE
            ? $liveDurable
            : (string) preg_replace('/_CONSUMER$/', '_TESTING_CONSUMER', $liveDurable);
    }

    /**
     * Every stream and durable a set of services needs on one tier.
     *
     * Streams are collected from both sides: a stream someone consumes has to
     * exist even when the service that publishes to it is not enabled on this
     * client, or the consumer could never be created.
     *
     * @param  iterable<Service>  $services
     * @return array{streams: array<string, string>, consumers: list<array{stream: string, durable: string}>}
     */
    public static function plan(iterable $services, string $tier): array
    {
        $streams = [];
        $consumers = [];

        foreach ($services as $service) {
            foreach ($service->nats_publishes ?? [] as $base) {
                $streams[self::stream($base, $tier)] = self::subject($base, $tier);
            }

            foreach ($service->nats_consumes ?? [] as $consumes) {
                $stream = self::stream($consumes['stream'], $tier);
                $streams[$stream] = self::subject($consumes['stream'], $tier);

                $consumers[] = [
                    'stream' => $stream,
                    'durable' => self::durable($consumes['durable'], $tier),
                ];
            }
        }

        ksort($streams);

        return ['streams' => $streams, 'consumers' => $consumers];
    }
}
