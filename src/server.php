<?php

use OpenSwoole\Atomic;
use OpenSwoole\Coroutine;
use OpenSwoole\WebSocket\Server;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Table;
use OpenSwoole\Timer;
use OpenSwoole\Util;
use OpenSwoole\WebSocket\Frame;

$listenHost = $argv[1] ?? "0.0.0.0";
$listenPort = intval($argv[2] ?? 7001);
$serverUrl = $argv[3] ?? "http://localhost:8000/";

$server = new Server($listenHost, $listenPort, Server::POOL_MODE);
$server->set([
    'max_connection' => 1000000,
    'max_coroutine' => 3000000,
    'reactor_num' => Util::getCPUNum() + 2,
    'worker_num' => Util::getCPUNum() * 4,
]);

$conns = new Atomic(0);
$rps = new Atomic(0);

$fds = new Table(1024 * 1024);
$fds->column('value', Table::TYPE_INT, 8);
$fds->create();
$addresses = new Table(1024 * 1024);
$addresses->column('value', Table::TYPE_STRING, 256);
$addresses->create();
$readLocks = [];

function fetchData(string $method, string $url, string $body, Atomic $counter): string|false
{
    while (!$counter->cmpset(0, 1)) {
        Coroutine::usleep(100);
    }
    Coroutine::defer(function () use ($counter) {
        $counter->set(0);
    });

    $parsedUrl = parse_url($url);
    $client = new Client($parsedUrl['host'], $parsedUrl['port'], $parsedUrl['scheme'] == 'https');
    $client->setMethod($method);
    if (strlen($body) > 0) {
        $client->setData($body);
    }
    $client->execute($parsedUrl['path']);
    $httpcode = $client->getStatusCode();
    $output = $client->getBody();
    return $httpcode == 200 ? $output : false;
}

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
    $response->status(400);
    $response->end("no upgrade requested");
});

$server->on("Handshake", function (Request $request, Response $response) use ($conns, $fds, $addresses, $serverUrl, &$readLocks): bool {
    $address = explode('/', $request->server['request_uri'])[1];
    $readLock = new Atomic(0);
    $readLocks[$address] = $readLock;
    $message = fetchData("GET", $serverUrl . $address, "", $readLock);
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
    $conns->add(1);
    return true;
});

$server->on('Message', function (Server $server, Frame $frame) use ($rps, $addresses, $serverUrl, &$readLocks): bool {
    $rps->add(1);
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
        $response = fetchData("POST", $serverUrl . $address, $frame->data, $readLock);
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

$server->on('Close', function (Server $server, int $fd) use ($fds, $addresses,  $serverUrl, &$readLocks) {
    $address = $addresses->get("$fd", "value");
    $readLock = $readLocks[$address];
    $addresses->del("$fd");
    $fds->del($address);
    $message = fetchData("DELETE", $serverUrl . $address, "", $readLock);
    if ($message != "ok") {
        echo "not allowed to disconnect: $message\n";
    }
});

if (!defined('TESTING')) {
    Timer::tick(1000, function () use ($rps, $conns) {
        static $seconds = 0;
        static $total = 0;
        if (!$seconds) echo "seconds,connections,rps,total\n";
        $seconds += 1;
        $conncount = $conns->get();
        $queriesps = $rps->get();
        $rps->set(0);
        $total += $queriesps;
        echo "$seconds,$conncount,$queriesps,$total\n";
    });
}

$server->start();
