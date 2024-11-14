### Installation

Follow https://openswoole.com/docs/get-started/prerequisites to install
openswoole, run:

    sudo apt install gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential

    sudo apt install php-curl php-json php-mysql php-common

    sudo pecl install openswoole-22.1.2 

    echo extension=/usr/lib/php/20230831/openswoole.so | sudo tee /etc/php/8.3/mods-available/openswoole.ini

    sudo ln -s /etc/php/8.3/mods-available/openswoole.ini /etc/php/8.3/cli/conf.d/30-openswoole.ini

Run to verfy succesful installation:

    echo "phpinfo();" | php -a | grep openswoole

I get:

    /etc/php/8.3/cli/conf.d/30-openswoole.ini
    openswoole
    Author => Open Swoole Group <hello@openswoole.com>
    openswoole.display_errors => On => On
    openswoole.enable_coroutine => On => On
    openswoole.enable_preemptive_scheduler => Off => Off
    openswoole.unixsock_buffer_size => 8388608 => 8388608

Which is what is expected.

    wget getcomposer.phar
    php composer.phar install

Now you can start editing in Visual Studio Code (Intelephense) and run the code using:

    php server.php

Enjoy!