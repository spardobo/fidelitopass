<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
require_once __DIR__.'/visit-registration-fixture.php';

try {
    $app = guardVisitBrowser();
} catch (Throwable $exception) {
    file_put_contents('php://stderr', $exception->getMessage()."\n");
    exit(1);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($uri, '/__visit-fixture/facts/')) {
    try {
        $facts = readVisitBrowserFacts(substr($uri, strlen('/__visit-fixture/facts/')));
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($facts, JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException) {
        http_response_code(404);
    }

    return;
}

// serve only real public files; never resolve an arbitrary caller-supplied router
$file = realpath(public_path().rawurldecode($uri));
if ($uri !== '/' && $file !== false && str_starts_with($file, public_path().DIRECTORY_SEPARATOR)
    && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

bindVisitBrowserWallet($app);
$app->handleRequest(Request::capture());
