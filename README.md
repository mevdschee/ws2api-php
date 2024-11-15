# WS2API PHP

This is a port of the [WS2API](https://github.com/mevdschee/ws2api) project into
OpenSwoole and Swow.

Both implementations are 200 lines of code and perform worse than the original
that is written in Go (and is also around 200 lines of code).

### Swow vs. OpenSwoole

The Swow implementation is faster but uses a single thread for accepting
connections. The OpenSwoole implementation is slower, but uses all cores of the
machine and therefor scales to higher connection counts.
