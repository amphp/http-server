<?php

/**
 * Usage:
 * 
 * Append to an existing stream resource to only write one byte of data per write operation ...
 * 
 * stream_filter_register("single_byte_write", "SingleByteWriteFilter");
 * $fp = fopen("php://memory", "w");
 * $filter = stream_filter_append($fp, "single_byte_write", STREAM_FILTER_WRITE);
 * fwrite($fp, 'some long string');
 * rewind($fp);
 * var_dump(stream_get_contents($fp)); // string(1) "s"
 * stream_filter_remove($filter);
 * 
 */
class SingleByteWriteFilter extends php_user_filter {
    
    function filter($in, $out, &$consumed, $closing) {
        if ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = $bucket->data[0];
            $consumed += 1;
            stream_bucket_append($out, $bucket);
        }
        
        return PSFS_PASS_ON;
    }
}
