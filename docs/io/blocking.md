---
title: Blocking I/O in Aerys
title_menu: Blocking I/O
layout: tutorial
---

```php
# DO NOT DO THIS

(new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
	$res->end("Some data");
	sleep(5); # or a blocking I/O function with 5 second timeout
});

# Open this route twice, you'll have to wait until the 5 seconds are over, until the next request is handled. (To try, start Aerys with only one worker: -w 1)
```

> **WARNING**
>
> DO NOT USE BLOCKING I/O FUNCTIONS IN AERYS!

Nearly every function built-in in PHP is doing blocking I/O, that means, the executing thread (equivalent to the process in the case of Aerys) will effectively be halted until the response is received. A few examples of such functions: `mysqli_query`, `file_get_contents`, `usleep` and many more.

A good rule of thumb is: every function doing I/O is doing it in a blocking way, unless you know for sure it doesn't.

Thus there are libraries like [amphp/mysql](../../mysql), [amphp/redis](../../redis) (and more), built on top of Amp providing non-blocking I/O. You should use these instead of the built-in functions.
