<?php

declare(strict_types=1);

// Answers exactly what the path asks for, so a test can say what it wants back.
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/status') {
    http_response_code((int) ($_GET['code'] ?? 200));
    echo 'status';

    exit;
}

if ($path === '/headers') {
    header('X-One: first');
    header('X-Two: second', false);
    header('X-Colon: a:b:c');
    echo 'headers';

    exit;
}

if ($path === '/echo') {
    header('Content-Type: application/json');

    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'target' => $_SERVER['REQUEST_URI'] ?? '',
        'body' => file_get_contents('php://input'),
        'sent' => $_SERVER['HTTP_X_SENT'] ?? null,
        'type' => $_SERVER['CONTENT_TYPE'] ?? null,
    ]);

    exit;
}

if ($path === '/slow') {
    sleep(5);
    echo 'late';

    exit;
}

if ($path === '/moved') {
    header('X-Left-Behind: 302');
    header('Location: /echo', true, 302);

    exit;
}

if ($path === '/teapot') {
    // A code no registry lists, with the reason phrase the server chose for it.
    header('HTTP/1.1 599 Something Nobody Registered');

    exit;
}

echo 'root';
