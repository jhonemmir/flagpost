<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\FlagpostConfig;
use Illuminate\Http\Request;
use Monolog\LogRecord;

/**
 * Defense in depth for log records: replaces known credentials in messages,
 * contexts and extra fields with a fixed marker.
 */
final class ScrubSecretsProcessor
{
    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = $this->secrets();

        if ($secrets === []) {
            return $record;
        }

        return $record->with(
            message: $this->scrubString($record->message, $secrets),
            context: $this->scrubArray($record->context, $secrets),
            extra: $this->scrubArray($record->extra, $secrets),
        );
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        $secrets = [FlagpostConfig::adminKey()];

        if (app()->bound('request')) {
            /** @var Request $request */
            $request = app('request');

            $secrets[] = (string) $request->headers->get('Authorization', '');
            $secrets[] = (string) $request->headers->get('Proxy-Authorization', '');
            $secrets[] = (string) $request->headers->get('X-Api-Key', '');
        }

        return array_values(array_unique(array_filter(
            $secrets,
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  list<string>  $secrets
     */
    private function scrubString(string $value, array $secrets): string
    {
        return str_replace($secrets, self::REDACTED, $value);
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $secrets
     * @return array<mixed>
     */
    private function scrubArray(array $values, array $secrets): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = $this->scrubString($value, $secrets);
            } elseif (is_array($value)) {
                $clean[$key] = $this->scrubArray($value, $secrets);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
