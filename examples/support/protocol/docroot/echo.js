
var RECENT_ECHOES_PREFIX = '0';
var USER_COUNT_PREFIX = '1';
var USER_ECHO_PREFIX = '2';

var uriScheme = (window.location.protocol === 'http:') ? 'ws' : 'wss';
var endpointUri = uriScheme + '://' + window.location.host + '/echo';
var connection = new WebSocket(endpointUri);
var cachedRowCount;

var messageTableBody = document.getElementById('messageTableBody');
var submitButton = document.getElementById('submitButton');
var theForm = document.getElementById('theform');
var clientCount = document.getElementById('clientCount');

connection.onopen = function(event) {
    console.log('Connected to ' + endpointUri + ' ...');
};

connection.onerror = function(event) {
    console.log(event.data);
    alert(event.data);
}

connection.onmessage = function(event) {
    var dataType = event.data[0];
    var data = event.data.substring(1);
    
    if (dataType === RECENT_ECHOES_PREFIX) {
        var recentEchoes = JSON.parse(data);
        cachedRowCount = recentEchoes.length;
        for (i=0; i<recentEchoes.length; i++) {
            appendRow(createMessageRow(recentEchoes[i]));
        }
        console.log('Recent Msgs rcvd: ' + data);
    } else if (dataType === USER_ECHO_PREFIX) {
        prependRow(createMessageRow(data));
        console.log('Msg rcvd: ' + data);
    } else if (dataType === USER_COUNT_PREFIX) {
        updateClientCount(data);
    }
};

var createMessageRow = function(data) {
    var tr = document.createElement('tr');
    var td = document.createElement('td');
    var text = document.createTextNode(data);
    
    tr.appendChild(td);
    td.appendChild(text);
    
    return tr;
};

var prependRow = function(row) {
    messageTableBody.insertBefore(row, messageTableBody.firstChild);
    cachedRowCount++;
    if (cachedRowCount > 10) {
        messageTableBody.removeChild(messageTableBody.lastChild);
    }
};

var appendRow = function(row) {
    messageTableBody.appendChild(row);
};

var updateClientCount = function(data) {
    clientCount.removeChild(clientCount.firstChild);
    var text = document.createTextNode(data);
    clientCount.insertBefore(text, clientCount.firstChild);
    console.log('User Count: ' + data);
};

var doSubmit = function(event) {
    var submitTextBox = document.getElementsByTagName('input')[0];
    
    if (submitTextBox.value.length > 0) {
        connection.send(submitTextBox.value);
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
