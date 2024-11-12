<?php

use OpenSwoole\WebSocket\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Runtime;
use OpenSwoole\Table;
use OpenSwoole\WebSocket\Frame;

$server = new Server("0.0.0.0", 4000);

$serverUrl = "http://localhost:5000/";

Runtime::enableCoroutine(Runtime::HOOK_NATIVE_CURL);
Runtime::enableCoroutine(false, Runtime::HOOK_SOCKETS);

$fds = new Table(1024 * 1024);
$fds->column('value', Table::TYPE_INT, 8);
$fds->create();
$addresses = new Table(1024 * 1024);
$addresses->column('value', Table::TYPE_STRING, 256);
$addresses->create();

function fetchData(string $url, string $body): string|false
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (strlen($body) > 0) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
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

$server->on("Handshake", function (Request $request, Response $response) use ($fds, $addresses, $serverUrl): bool {
    $address = explode('/', $request->server['request_uri'])[1];
    $message = fetchData($serverUrl . $address, "");
    if ($message === false) {
        $response->status(502);
        $response->end("error when proxying connect");
        return false;
    }
    if ($message != "ok") {
        $response->status(403);
        $response->end("not allowed to connect");
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

$server->on('Message', function (Server $server, Frame $frame) use ($addresses, $serverUrl) {
    $address = $addresses->get("$frame->fd", "value");
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_BINARY) {
        echo "binary messages not supported\n";
        return;
    }
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_PING) {
        $pongFrame = new Frame();
        $pongFrame->opcode = Server::WEBSOCKET_OPCODE_PONG;
        $server->push($frame->fd, $pongFrame);
        return;
    }
    if ($frame->opcode === Server::WEBSOCKET_OPCODE_TEXT) {
        $response = fetchData($serverUrl . $address, $frame->data);
        if (strlen($response ?: '') > 0) {
            $responseFrame = new Frame();
            $responseFrame->opcode = Server::WEBSOCKET_OPCODE_TEXT;
            $responseFrame->data = $response;
            $server->push($frame->fd, $responseFrame);
        }
        return true;
    }
});

$server->on('Disconnect', function (Server $server, int $fd) use ($fds, $addresses) {
    $address = $addresses->get("$fd", "value");
    $addresses->del("$fd");
    $fds->del($address);
});

$server->start();
