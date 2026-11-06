<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api' || str_starts_with($path ?? '', '/api/')) {
    require __DIR__.'/../backend/public/index.php';

    return true;
}

$build = __DIR__.DIRECTORY_SEPARATOR.'dist';
if ($path === '/cpstl-logo.png' || preg_match('~^/assets/[A-Za-z0-9_.-]+\.(?:js|css|png|svg|woff2)$~D', $path ?? '')) {
    return false;
}
if (str_starts_with($path ?? '', '/assets/')) {
    http_response_code(404);

    return true;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($build.DIRECTORY_SEPARATOR.'index.html');

return true;
