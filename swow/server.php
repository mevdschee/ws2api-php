<?php

declare(strict_types=1);

use OpenSwoole\Atomic;
use Swow\Coroutine;
use Swow\CoroutineException;
use Swow\Errno;
use Swow\Http\Protocol\ProtocolException as HttpProtocolException;
use Swow\Http\Status as HttpStatus;
use Swow\Psr7\Message\UpgradeType;
use Swow\Psr7\Psr7;
use Swow\Psr7\Server\Server;
use Swow\SocketException;
use Swow\WebSocket\Opcode as WebSocketOpcode;
use Swow\WebSocket\WebSocket;

require 'vendor/autoload.php';

$server = new Server();
$server->bind('0.0.0.0', 7001)->listen();

echo "SWOW websocket server started at http://127.0.0.1:7001\n";

$serverUrl = "http://localhost:8000/";

$connections = [];

$qps = 0;
$conns = 0;

function fetchData(string $url, string $body): string|false
{
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

Coroutine::run(static function () use (&$qps, &$conns): void {
    while (true) {
        sleep(1);
        static $seconds = 0;
        static $total = 0;
        if (!$seconds) echo "seconds,connections,qps,total\n";
        $seconds += 1;
        $queriesps = $qps;
        $qps = 0;
        $total += $queriesps;
        echo "$seconds,$conns,$queriesps,$total\n";
    }
});

while (true) {
    try {
        $connection = null;
        $connection = $server->acceptConnection();
        Coroutine::run(static function () use (&$qps, &$conns, $connection, $serverUrl, &$connections): void {
            try {
                while (true) {
                    $address = "";
                    $request = null;
                    try {
                        $request = $connection->recvHttpRequest();
                        $address = explode('/', $request->getUri()->getPath())[1];
                        if (strlen($address) == 0) {
                            $connection->error(HttpStatus::BAD_REQUEST, "invalid url, use /address", true);
                        }
                        if ($request->getMethod() == "POST") {
                            $conn = $connections[$address] ?? false;
                            if ($conn !== false) {
                                $connection->error(HttpStatus::NOT_FOUND, "could not find address: $address", true);
                            }
                            $message = $request->getBody()->__toString();
                            if (!$message) {
                                $connection->error(HttpStatus::INTERNAL_SERVER_ERROR, "could not read body", true);
                            }
                            $success = $connection->sendWebSocketFrame(
                                Psr7::createWebSocketTextFrame(
                                    payloadData: $message,
                                )
                            );
                            if (!$success) {
                                $connection->error(HttpStatus::INTERNAL_SERVER_ERROR, "could not send request", true);
                            }
                            $connection->respond("ok");
                            return;
                        }
                        $upgradeType = Psr7::detectUpgradeType($request);
                        if ($upgradeType & UpgradeType::UPGRADE_TYPE_WEBSOCKET != UpgradeType::UPGRADE_TYPE_WEBSOCKET) {
                            $connection->error(HttpStatus::BAD_REQUEST, "no upgrade requested", true);
                        }
                        $connection->upgradeToWebSocket($request);
                        $request = null;
                        $conns += 1;
                        while (true) {
                            $qps += 1;
                            $frame = $connection->recvWebSocketFrame();
                            $opcode = $frame->getOpcode();
                            switch ($opcode) {
                                case WebSocketOpcode::BINARY:
                                    echo "binary messages not supported\n";
                                    break;
                                case WebSocketOpcode::PING:
                                    $connection->send(WebSocket::PONG_FRAME);
                                    break;
                                case WebSocketOpcode::PONG:
                                    break;
                                case WebSocketOpcode::CLOSE:
                                    break 2;
                                default:
                                    $frameData = $frame->getPayloadData()->__toString();
                                    $response = fetchData($serverUrl . $address, $frameData);
                                    if ($response === false) {
                                        echo "error when proxying request\n";
                                        break;
                                    }
                                    if (strlen($response ?: '') > 0) {
                                        $connection->sendWebSocketFrame(
                                            Psr7::createWebSocketTextFrame(
                                                payloadData: $response,
                                            )
                                        );
                                    }
                            }
                        }
                    } catch (HttpProtocolException $exception) {
                        $connection->error($exception->getCode(), $exception->getMessage(), close: true);
                        break;
                    } finally {
                        if (isset($connections[$address])) {
                            unset($connections[$address]);
                        }
                    }
                    if (!$connection->shouldKeepAlive()) {
                        break;
                    }
                }
            } catch (Exception) {
                // you can log error here
            } finally {
                $connection->close();
            }
        });
    } catch (SocketException | CoroutineException $exception) {
        if (in_array($exception->getCode(), [Errno::EMFILE, Errno::ENFILE, Errno::ENOMEM], true)) {
            sleep(1);
        } else {
            break;
        }
    }
}
