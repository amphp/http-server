
var scheme = (window.location.protocol === 'http:') ? 'ws' : 'wss';
var uri = scheme + '://' + window.location.host + '/dbg';
var conn = new WebSocket(uri);

conn.onopen = function(event) {
    console.log('Connected to ' + uri + ' ...');
};

conn.onerror = function(event) {
    console.log(event.data);
    alert(event.data);
}

conn.onmessage = function(event) {
    console.log(event.data);
};
