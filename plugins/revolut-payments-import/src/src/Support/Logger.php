<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * Timestamped logging with token redaction. The sink is a callable taking a
 * single string, so production wires it to PluginLogManager::appendLog and
 * tests can capture lines.
 */
final class Logger
{
    /** @var callable(string):void */
    private $sink;

    /** @param callable(string):void $sink */
    public function __construct(callable $sink)
    {
        $this->sink = $sink;
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        ($this->sink)(sprintf('[%s] %s: %s', $timestamp, $level, self::redact($message)));
    }

    /**
     * Redact bearer tokens / secrets that may slip into log strings.
     */
    public static function redact(string $message): string
    {
        $patterns = [
            '/(oa_(?:prod|sand)_[A-Za-z0-9_\-]{4})[A-Za-z0-9_\-]+/',
            '/(wsk_[A-Za-z0-9_\-]{4})[A-Za-z0-9_\-]+/',
        ];

        return (string) preg_replace($patterns, '$1***', $message);
    }
}
