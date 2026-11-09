<?php

namespace Rss\Vlog\Core\Index;

use Rss\Vlog\Core\Entry\EntryHeader;
use Rss\Vlog\Core\Parsers\Parser;

/**
 * Finds where entries start by reading only the first line of each one.
 */
final class IndexScanner
{
    /**
     * The most bytes of a line checked for an entry header. The rest of a
     * longer line is skipped.
     */
    private const MAX_HEADER_BYTES = 65536;

    /**
     * How many lines are read between checks of the time limit.
     */
    private const LINES_PER_TIME_CHECK = 2048;

    private Parser $parser;

    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Scan complete lines from a byte offset and report each entry's start.
     *
     * A last line without a line break may still be being written, so it's
     * left for a later scan.
     *
     * @param  resource  $handle
     * @param  bool  $inEntry  Whether the offset is inside an entry, so lines without a header continue it.
     * @param  callable(int, EntryHeader): void  $onEntry  Called with each entry's byte offset and header.
     * @param  float|null  $deadline  A microtime(true) value to stop scanning at.
     * @return array{0: int, 1: bool} The offset after the last line scanned, and whether the scan reached the end of the file.
     */
    public function scan($handle, int $offset, bool $inEntry, callable $onEntry, ?float $deadline = null): array
    {
        fseek($handle, $offset);
        $position = $offset;
        $lines = 0;

        while (true) {
            if ($deadline !== null && ++$lines % self::LINES_PER_TIME_CHECK === 0 && microtime(true) >= $deadline) {
                return [$position, false];
            }

            $line = fgets($handle, self::MAX_HEADER_BYTES);

            if ($line === false) {
                return [$position, true];
            }

            $bytes = strlen($line);
            $complete = substr($line, -1) === "\n";

            while (! $complete && ($rest = fgets($handle, self::MAX_HEADER_BYTES)) !== false) {
                $bytes += strlen($rest);
                $complete = substr($rest, -1) === "\n";
            }

            if (! $complete) {
                return [$position, true];
            }

            $lineStart = $position;
            $position += $bytes;
            $line = rtrim($line, "\r\n");
            $header = $this->parser->headerOf($line);

            if ($header === null) {
                if ($inEntry || $this->parser->isNoise($line)) {
                    continue;
                }

                $header = new EntryHeader(null, null, $line);
            }

            $onEntry($lineStart, $header);
            $inEntry = true;
        }
    }
}
