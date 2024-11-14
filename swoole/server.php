<?php

use OpenSwoole\Atomic;
use OpenSwoole\Coroutine;
use OpenSwoole\WebSocket\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Runtime;
use OpenSwoole\Table;
use OpenSwoole\Util;
use OpenSwoole\WebSocket\Frame;

//ini_set('swoole.enable_preemptive_scheduler', '1');

$server = new Server("0.0.0.0", 4000);
$server->set([
    'max_connection' => 1000000,
    'max_coroutine' => 3000000,
    'reactor_num' => Util::getCPUNum() + 2,
    'worker_num' => Util::getCPUNum() * 4,
]);

$serverUrl = "http://localhost:5000/";

Runtime::enableCoroutine(Runtime::HOOK_NATIVE_CURL);

$fds = new Table(1024 * 1024);
$fds->column('value', Table::TYPE_INT, 8);
$fds->create();
$addresses = new Table(1024 * 1024);
$addresses->column('value', Table::TYPE_STRING, 256);
$addresses->create();
$readLocks = [];

function fetchData(string $url, string $body, Atomic $counter): string|false
{
    while (!$counter->cmpset(0, 1)) {
        Coroutine::usleep(100);
    }
    Coroutine::defer(function () use ($counter) {
        $counter->set(0);
    });

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (strlen($body) > 0) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    //curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    //curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpcode == 200 ? $output : false;
}

$server->on("Start", function (Server $server) {
    echo "OpenSwoole WebSocket Server is started at http://127.0.0.1:4000\n";
});

// The Request event closure callback is passed the context of $server
$server->on('Request', function (Request $request, Response $response) use ($fds, $server) {
    $address = explode('/', $request->server['request_uri'])[1];
    if (strlen($address) == 0) {
        $response->status(400);
        $response->end("invalid url, use /address");
        return;
    }
    if ($request->getMethod() == "POST") {
        $fd = $fds->get($address, 'value') ?? false;
        if ($fd === false) {
            $response->status(404);
            $response->end("could not find address: $address");
            return;
        }
        $message = $request->getContent();
        if ($message === false) {
            $response->status(500);
            $response->end("could not read body");
            return;
        }
        $success = $server->push($fd, $message);
        if ($success === false) {
            $response->status(500);
            $response->end("could not send request");
            return;
        }
        $response->end("ok");
        return;
    }
    $response->status(500);
    $response->end("no upgrade requested");
});

$server->on("Handshake", function (Request $request, Response $response) use ($fds, $addresses, $serverUrl, &$readLocks): bool {
    $address = explode('/', $request->server['request_uri'])[1];
    $readLock = new Atomic(0);
    $readLocks[$address] = $readLock;
    $message = fetchData($serverUrl . $address, "", $readLock);
    if ($message === false) {
        $response->status(502);
        $response->end("error when proxying connect");
        echo "error when proxying connect\n";
        return false;
    }
    if ($message != "ok") {
        $response->status(403);
        $response->end("not allowed to connect");
        echo "not allowed to connect: $message\n";
        return false;
    }
    $fds->set($address, ['value' => $request->fd]);
    $addresses->set("$request->fd", ['value' => $address]);
    $key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $headers = [
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Accept' => $key,
        'Sec-WebSocket-Version' => '13',
    ];
    if (isset($request->header['sec-websocket-protocol'])) {
        $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
    }
    foreach ($headers as $key => $val) {
        $response->header($key, $val);
    }
    $response->status(101);
    $response->end();
    return true;
});

$server->on('Message', function (Server $server, Frame $frame) use ($addresses, $serverUrl, &$readLocks): bool {
    $address = $addresses->get("$frame->fd", "value");
    $readLock = $readLocks[$address];
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_BINARY) {
        echo "binary messages not supported\n";
        return false;
    }
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_PING) {
        $pongFrame = new Frame();
        $pongFrame->opcode = Server::WEBSOCKET_OPCODE_PONG;
        $server->push($frame->fd, $pongFrame);
        return true;
    }
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_TEXT) {
        $response = fetchData($serverUrl . $address, $frame->data, $readLock);
        if ($response === false) {
            echo "error when proxying request\n";
            return false;
        }
        if (strlen($response ?: '') > 0) {
            $responseFrame = new Frame();
            $responseFrame->opcode = Server::WEBSOCKET_OPCODE_TEXT;
            $responseFrame->data = $response;
            $server->push($frame->fd, $responseFrame);
        }
        return true;
    }
    return false;
});

$server->on('Disconnect', function (Server $server, int $fd) use ($fds, $addresses) {
    $address = $addresses->get("$fd", "value");
    $addresses->del("$fd");
    $fds->del($address);
});

$server->start();
