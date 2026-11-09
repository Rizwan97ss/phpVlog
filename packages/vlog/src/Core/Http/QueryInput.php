<?php

namespace Rss\Vlog\Core\Http;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Reads typed values from a request's query string.
 *
 * Frameworks pass query values as strings or arrays, so each method checks
 * the type and rejects values it can't use.
 */
final class QueryInput
{
    /**
     * @var array<array-key, mixed>
     */
    private array $query;

    /**
     * @param  array<array-key, mixed>  $query
     */
    public function __construct(array $query)
    {
        $this->query = $query;
    }

    /**
     * Get a non-empty string, or null when it's missing or empty.
     *
     * @throws InvalidArgumentException
     */
    public function string(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        // JSON request bodies hold numbers and booleans as their own types.
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$key} parameter must be a string.");
        }

        return $value;
    }

    /**
     * Get a JSON object, such as an entry's context, or null when it's missing.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidArgumentException
     */
    public function object(string $key, int $maxBytes): ?array
    {
        $value = $this->query[$key] ?? null;

        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} parameter must be an object.");
        }

        if (strlen((string) json_encode($value)) > $maxBytes) {
            throw new InvalidArgumentException("The {$key} parameter can be up to {$maxBytes} bytes.");
        }

        return $value;
    }

    /**
     * Get a whole number between a minimum and maximum.
     *
     * @throws InvalidArgumentException
     */
    public function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $this->string($key);

        if ($value === null) {
            return $default;
        }

        if (preg_match('/^-?\d+$/', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException("The {$key} parameter must be a whole number from {$minimum} to {$maximum}.");
        }

        return (int) $value;
    }

    /**
     * Get an optional whole number of at least a minimum.
     *
     * @throws InvalidArgumentException
     */
    public function optionalInteger(string $key, int $minimum): ?int
    {
        return $this->string($key) === null ? null : $this->integer($key, $minimum, $minimum, PHP_INT_MAX);
    }

    /**
     * Get a boolean written as "1", "true", "0" or "false".
     *
     * @throws InvalidArgumentException
     */
    public function boolean(string $key): bool
    {
        $value = $this->string($key);

        if ($value === null) {
            return false;
        }

        if (! in_array($value, ['1', 'true', '0', 'false'], true)) {
            throw new InvalidArgumentException("The {$key} parameter must be true or false.");
        }

        return $value === '1' || $value === 'true';
    }

    /**
     * Get one of the allowed values.
     *
     * @param  list<string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    public function choice(string $key, array $allowed, string $default): string
    {
        $value = $this->string($key) ?? $default;

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("The {$key} parameter must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * Get a comma-separated list, or null when it's missing.
     *
     * @return list<string>|null
     *
     * @throws InvalidArgumentException
     */
    public function list(string $key): ?array
    {
        $value = $this->string($key);

        if ($value === null) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), function (string $item): bool {
            return $item !== '';
        }));
    }

    /**
     * Get a date and time, in the server's time zone unless it includes one.
     *
     * @throws InvalidArgumentException
     */
    public function dateTime(string $key): ?DateTimeImmutable
    {
        $value = $this->string($key);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException("The {$key} parameter must be a valid date and time.");
        }
    }
}
