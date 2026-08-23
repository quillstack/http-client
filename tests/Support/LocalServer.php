<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Tests\Support;

use RuntimeException;

/**
 * A server on this machine, for the length of one suite.
 *
 * A client is not much use tested against nothing, and it is less use tested against
 * somebody else's website: what a test needs is an end which answers exactly as asked.
 */
final class LocalServer
{
    /**
     * @var resource|null
     */
    private static mixed $process = null;

    private static string $url = '';

    public static function url(): string
    {
        if (self::$url !== '') {
            return self::$url;
        }

        $port = 8000 + random_int(100, 900);

        // As an array rather than a string, so that no shell is involved: given a string,
        // `proc_open()` runs `sh -c` and hands back the shell. `proc_terminate()` then kills
        // the shell and leaves the server running — one left behind per suite, each holding
        // the port it was given until the machine is restarted.
        $process = proc_open(
            [self::php(), '-S', "127.0.0.1:{$port}", '-t', __DIR__ . '/public'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The test server could not be started');
        }

        self::$process = $process;
        $url = "http://127.0.0.1:{$port}";

        // Waiting for it rather than sleeping a fixed time, which is either too long or not
        // long enough on whichever machine runs it next.
        for ($try = 0; $try < 100; ++$try) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return self::$url = $url;
            }

            usleep(50_000);
        }

        // Rather than an empty URL, which every test then reports as a URI with no host in it
        // — twelve failures naming the wrong thing, none of them naming this.
        throw new RuntimeException("The test server did not answer on port {$port}");
    }

    /**
     * The command line PHP, which is not always the one running this.
     *
     * Under phpdbg — which is how the coverage run measures anything — `PHP_BINARY` is the
     * debugger, and `phpdbg -S` is not a web server.
     */
    private static function php(): string
    {
        $php = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';

        return is_executable($php) ? $php : PHP_BINARY;
    }

    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;
        self::$url = '';
    }
}
