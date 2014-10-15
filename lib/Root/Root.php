<?php

namespace Aerys\Root;

interface Root {
    const OP_INDEXES = 1;
    const OP_ETAG_FLAGS = 2;
    const OP_EXPIRES_PERIOD = 3;
    const OP_DEFAULT_MIME = 4;
    const OP_DEFAULT_CHARSET = 5;
    const OP_ENABLE_CACHE = 6;
    const OP_CACHE_TTL = 7;
    const OP_CACHE_MAX_ENTRIES = 8;
    const OP_CACHE_MAX_ENTRY_SIZE = 9;

    const ETAG_NONE = 0;
    const ETAG_SIZE = 1;
    const ETAG_INODE = 2;
    const ETAG_ALL = 3;
}
