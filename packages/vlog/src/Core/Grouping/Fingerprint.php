<?php

namespace Rss\Vlog\Core\Grouping;

/**
 * Turns a message into a pattern that's the same for every occurrence of an
 * error, by replacing the parts that change between occurrences.
 *
 * "Order 1042 failed for amy@example.com" and "Order 1043 failed for
 * bo@example.com" both become "Order {n} failed for {email}".
 */
final class Fingerprint
{
    /**
     * The most characters of a message used for its pattern.
     */
    private const MAX_LENGTH = 300;

    /**
     * Where the JSON context Monolog writes after a message can start.
     */
    private const CONTEXT_STARTS = [' {"', ' {}', ' []', ' [{', ' ["'];

    /**
     * The changing parts of a message, in the order they're replaced.
     */
    private const PATTERNS = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/' => '{email}',
        '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '{ip}',
        '/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i' => '{hex}',
        '/\d+(?:\.\d+)?/' => '{n}',
        '/\s+/' => ' ',
    ];

    /**
     * Get a message's pattern, without the context Monolog writes after it.
     */
    public static function pattern(string $message): string
    {
        $message = self::withoutContext($message);
        $pattern = preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $message);

        return trim(is_string($pattern) ? $pattern : $message);
    }

    /**
     * Get a cheap key that's the same for messages whose patterns are the
     * same because they only differ in their digits.
     *
     * Every pattern treats all digits alike, so replacing each digit with "0"
     * keeps the length and shape the patterns look at. Messages with the same
     * shape key always have the same pattern.
     */
    public static function shapeKey(string $message): string
    {
        return strtr(self::withoutContext($message), '123456789', '000000000');
    }

    /**
     * Cut off the context Monolog writes after a message, and limit its length.
     */
    private static function withoutContext(string $message): string
    {
        $end = strlen($message);

        foreach (self::CONTEXT_STARTS as $contextStart) {
            $position = strpos($message, $contextStart);

            if ($position !== false && $position < $end) {
                $end = $position;
            }
        }

        return substr($message, 0, min($end, self::MAX_LENGTH));
    }

    /**
     * Get the key that identifies a group: its level and pattern.
     */
    public static function key(?string $level, string $pattern): string
    {
        return substr(sha1(($level ?? '').'|'.$pattern), 0, 16);
    }
}
