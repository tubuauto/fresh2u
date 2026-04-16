<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Shared\Http\ApiKernel;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Support\Exceptions\HttpException;

$appConfig = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
$request = Request::fromGlobals();
$currentPath = $request->path;

if ($currentPath === '/health') {
    Response::json(['ok' => true], 200);
    exit;
}

if ($request->method === 'GET') {
    if ($currentPath === '/' || $currentPath === '/index.php') {
        servePublicFile(__DIR__ . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'home.html');
        exit;
    }

    if (tryServePublicAsset($currentPath, __DIR__)) {
        exit;
    }
}

if (isApiPath($currentPath)) {
    try {
        $kernel = new ApiKernel($appConfig);
        [$status, $payload] = $kernel->handle($request);
        Response::json($payload, $status);
    } catch (HttpException $e) {
        Response::json(['error' => $e->getMessage()], $e->statusCode());
    } catch (Throwable $e) {
        $message = $appConfig['debug'] ? $e->getMessage() : 'Internal server error';
        Response::json(['error' => $message], 500);
    }
    exit;
}

if ($request->method === 'GET') {
    servePublicFile(__DIR__ . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'home.html');
    exit;
}

Response::json(['error' => 'Route not found'], 404);

function isApiPath(string $path): bool
{
    return str_starts_with($path, '/api/');
}

function tryServePublicAsset(string $path, string $publicRoot): bool
{
    $relative = ltrim(urldecode($path), '/');
    if ($relative === '') {
        return false;
    }

    $target = realpath($publicRoot . DIRECTORY_SEPARATOR . $relative);
    $root = realpath($publicRoot);
    if ($target === false || $root === false || !is_file($target)) {
        return false;
    }

    $normalizedRoot = str_replace('\\', '/', $root);
    $normalizedTarget = str_replace('\\', '/', $target);
    if (!str_starts_with($normalizedTarget, $normalizedRoot . '/')) {
        return false;
    }

    $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $allowed = [
        'html', 'css', 'js', 'json', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2',
    ];

    if (!in_array($extension, $allowed, true)) {
        return false;
    }

    servePublicFile($target);
    return true;
}

function servePublicFile(string $file): void
{
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        return;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeMap = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    $mime = $mimeMap[$extension] ?? 'application/octet-stream';

    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=1800');
    readfile($file);
}