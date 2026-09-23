<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Turns a Guzzle transport exception into a RevolutApiException with a concise,
 * loggable message and the classified HTTP status. Reads at most a 200-char
 * excerpt of the response body and never includes request headers, so the
 * bearer token cannot leak (Logger::redact strips oa_/wsk_ tokens too).
 */
final class RevolutErrorMapper
{
    private const BODY_EXCERPT = 200;

    public static function fromGuzzle(TransferException $e, string $method, string $path): RevolutApiException
    {
        $status = null;
        $revolutCode = null;
        $retryAfter = null;
        // Default to Guzzle's message ONLY for non-HTTP failures (ConnectException):
        // it has no response and its text does not contain the request URL/query.
        $detail = $e->getMessage();

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            // For an HTTP error, NEVER fall back to Guzzle's message — it embeds the
            // full request URL incl. the query string (which may carry ids). Extract
            // the detail from the body; if none, use the neutral reason phrase.
            $detail = $response->getReasonPhrase();
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if (isset($decoded['code']) && is_numeric($decoded['code'])) {
                    $revolutCode = (int) $decoded['code'];
                }
                foreach (['message', 'error_description', 'error'] as $key) {
                    if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                        $detail = $decoded[$key];
                        break;
                    }
                }
            } elseif ($body !== '') {
                $detail = substr($body, 0, self::BODY_EXCERPT);
            }

            $ra = $response->getHeaderLine('Retry-After');
            if ($ra !== '' && ctype_digit($ra)) {
                $retryAfter = (int) $ra;
            }
        }

        $detail = trim(substr($detail, 0, self::BODY_EXCERPT));

        $message = $status !== null
            ? sprintf(
                'Revolut API %d on %s %s%s: %s',
                $status,
                $method,
                $path,
                $revolutCode !== null ? sprintf(' (code %d)', $revolutCode) : '',
                $detail,
            )
            : sprintf('Revolut API unreachable on %s %s: %s', $method, $path, $detail);

        return new RevolutApiException($message, $method, $path, $status, $revolutCode, $retryAfter, $e);
    }
}
