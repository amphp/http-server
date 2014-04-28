<?php

namespace Aerys;

// --- global server options -----------------------------------------------------------------------

const MAX_CONNECTIONS = 1000;
const MAX_REQUESTS = 100;
const KEEP_ALIVE_TIMEOUT = 5;
const MAX_HEADER_BYTES = 8192;
const MAX_BODY_BYTES = 2097152;
const DEFAULT_CONTENT_TYPE = 'text/html';
const DEFAULT_TEXT_CHARSET = 'utf-8';
const AUTO_REASON_PHRASE = TRUE;
const SEND_SERVER_TOKEN = FALSE;
const SOCKET_BACKLOG_SIZE = 128;
const ALLOWED_METHODS = 'GET, HEAD, OPTIONS, TRACE, PUT, POST, PATCH, DELETE';


// --- mysite.com ----------------------------------------------------------------------------------

$mysite = new HostConfig('mysite.com');
$mysite->addRoute('GET', '/non-blocking', 'myNonBlockingRoute');
$mysite->addThreadRoute('GET', '/', 'myThreadRoute');
$mysite->addResponder('myFallbackResponder');


// --- static.mysite.com ---------------------------------------------------------------------------

$statics = new HostConfig('static.mysite.com');
$statics->setDocumentRoot('/path/to/static/files');
