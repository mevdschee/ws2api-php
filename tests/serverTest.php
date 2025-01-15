<?php

declare(strict_types=1);

use OpenSwoole\Server;
use PHPUnit\Framework\TestCase;

define('TESTING', 1);
//include 'server.php';

final class serverTest extends TestCase
{
    public function testGreetsWithName(): void
    {
        $greeter = new Server();

        $greeting = $greeter->greet('Alice');

        $this->assertSame('Hello, Alice!', $greeting);
    }
}
