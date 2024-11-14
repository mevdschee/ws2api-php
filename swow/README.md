
### Installation

Install requirements using Composer with:

    wget getcomposer.org/composer.phar
    php composer.phar require swow/swow

Now build the extension using:

    vendor/bin/swow-builder

And install it using:

    sudo vendor/bin/swow-builder

You will find a file is created as:

    /usr/lib/php/20230831/swow.so

You need to add that extension to an ini file:

    echo extension=/usr/lib/php/20230831/swow.so | sudo tee /etc/php/8.3/mods-available/swow.ini

You need to enable that extension using:

    sudo ln -s /etc/php/8.3/mods-available/swow.ini /etc/php/8.3/cli/conf.d/30-swow.ini

You can ensure that this extension is installed using:

    echo "phpinfo();" | php -a | grep swow

It should show something like:

    /etc/php/8.3/cli/conf.d/30-swow.ini
    Link => https://github.com/swow/swow
    swow.async_file => On => On
    swow.async_threads => 0 => 0
    swow.async_tty => On => On
    swow.enable => On => On

Meaning that it is installed.