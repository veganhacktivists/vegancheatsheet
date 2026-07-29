<?php
// Boots the built-in server against the repository root and drives `index.php`
// over HTTP, so the redirect, the `exit` and the emitted page are exercised the
// way a visitor hits them. Run with `php tests/run.php`.

const HOST = '127.0.0.1';
const PORT = 8571;
const SHEET_ID = '19Cm5yHp16zSTSFrQ3B_3_vIK0b5QlJ8jpyPVrPrCKS0';
const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const DESKTOP_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'vcs-server-');
$visitors = $root . '/visitors.txt';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
        return;
    }
    $failed++;
    echo "  FAIL $name\n";
    if ($detail !== '') {
        echo "       $detail\n";
    }
}

// Raw socket rather than the HTTP wrapper so a request with no `User-Agent`
// really sends none.
function get(?string $userAgent): array
{
    $socket = @fsockopen(HOST, PORT, $errno, $errstr, 5);
    if ($socket === false) {
        throw new RuntimeException("connection failed: $errstr");
    }
    $request = "GET / HTTP/1.1\r\nHost: " . HOST . ':' . PORT . "\r\n";
    if ($userAgent !== null) {
        $request .= "User-Agent: $userAgent\r\n";
    }
    fwrite($socket, $request . "Connection: close\r\n\r\n");
    $raw = stream_get_contents($socket);
    fclose($socket);

    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    preg_match('#^HTTP/\S+ (\d+)#', $head, $status);
    preg_match('#^Location: (.*)$#mi', $head, $location);

    return [
        'status' => isset($status[1]) ? (int) $status[1] : 0,
        'location' => isset($location[1]) ? trim($location[1]) : null,
        'body' => $body,
    ];
}

@unlink($visitors);

$command = sprintf(
    '%s -d display_errors=1 -d error_reporting=E_ALL -S %s:%d -t %s',
    escapeshellarg(PHP_BINARY),
    HOST,
    PORT,
    escapeshellarg($root)
);
$server = proc_open($command, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the test server\n");
    exit(1);
}

for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @fsockopen(HOST, PORT, $errno, $errstr, 1);
    if ($probe !== false) {
        fclose($probe);
        break;
    }
    usleep(100000);
}

try {
    echo "Mobile visitors\n";
    $mobile = get(MOBILE_UA);
    check('are redirected', $mobile['status'] === 302, "got status {$mobile['status']}");
    check('land on the maintained sheet', is_string($mobile['location']) && str_contains($mobile['location'], SHEET_ID), 'Location: ' . var_export($mobile['location'], true));
    check('are not also served the page', trim($mobile['body']) === '', 'body was ' . strlen($mobile['body']) . ' bytes');

    echo "Desktop visitors\n";
    $desktop = get(DESKTOP_UA);
    check('are not redirected', $desktop['status'] === 200 && $desktop['location'] === null, "status {$desktop['status']}, Location " . var_export($desktop['location'], true));
    check('get the sheet in an iframe', (bool) preg_match('#<iframe[^>]+src="[^"]*' . SHEET_ID . '#', $desktop['body']));

    echo "Both surfaces\n";
    preg_match_all('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $mobile['location'] . ' ' . $desktop['body'], $sheets);
    $referenced = array_values(array_unique($sheets[1]));
    check('point at the same sheet', $referenced === [SHEET_ID], 'referenced: ' . implode(', ', $referenced));

    echo "A request with no User-Agent\n";
    $anonymous = get(null);
    check('is served the page', $anonymous['status'] === 200, "got status {$anonymous['status']}");
    check('raises no PHP diagnostics', !preg_match('#\b(Warning|Deprecated|Notice|Fatal error)\b#', $anonymous['body']), trim(substr($anonymous['body'], 0, 200)));

    echo "The page itself\n";
    check('carries no dead analytics tag', !str_contains($desktop['body'], 'UA-150398169-4') && !str_contains($desktop['body'], 'googletagmanager'));
    check('writes no visitor counter', !file_exists($visitors));

    $logged = (string) file_get_contents($log);
    check('logs no PHP diagnostics server-side', !preg_match('#PHP (Warning|Deprecated|Notice|Fatal error)#', $logged), trim($logged));
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($log);
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
