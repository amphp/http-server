var scheme = (window.location.protocol === 'http:') ? 'ws' : 'wss';
var uri = scheme + '://' + window.location.host + '/echo';
var conn = new WebSocket(uri);

var messageTableBody = document.getElementById('messageTableBody');
var submitButton = document.getElementById('submitButton');
var theForm = document.getElementById('theForm');
var clientCount = document.getElementById('clientCount');

conn.onopen = function(event) {
    console.log('Connected to ' + uri + ' ...');
};

conn.onerror = function(event) {
    console.log(event.data);
    alert(event.data);
}

conn.onmessage = function(event) {
    var msg = JSON.parse(event.data);

    if (msg.type === 'count') {
        updateClientCount(msg.data);
        console.log('{users}: ' + msg.data);
    } else if (msg.type === 'echo') {
        prependRow(createMessageRow(msg.data));
        console.log('{data}: ' + msg.data);
    }
};

var createMessageRow = function(str) {
    var tr = document.createElement('tr');
    var td = document.createElement('td');
    var text = document.createTextNode(str);

    tr.appendChild(td);
    td.appendChild(text);

    return tr;
};

var prependRow = function(row) {
    messageTableBody.insertBefore(row, messageTableBody.firstChild);
};

var appendRow = function(row) {
    messageTableBody.appendChild(row);
};

var updateClientCount = function(str) {
    clientCount.removeChild(clientCount.firstChild);
    var text = document.createTextNode(str);
    clientCount.insertBefore(text, clientCount.firstChild);
    console.log('User Count: ' + str);
};

var doSubmit = function(event) {
    var submitTextBox = document.getElementsByTagName('input')[0];

    if (submitTextBox.value.length > 0) {
        conn.send(submitTextBox.value);
        console.log('Msg sent: ' + submitTextBox.value);

        row = createMessageRow(submitTextBox.value);
        prependRow(row);

        submitTextBox.value = '';
        submitTextBox.focus();
    }

    return false;
};

submitButton.onclick = doSubmit;
theForm.onSubmit = doSubmit;
