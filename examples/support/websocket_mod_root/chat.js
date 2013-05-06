// Please excuse my ugly javascript.

var endpoint = 'ws://66.57.216.51:1337/chat'; // address of your websocket endpoint here
var submit = document.getElementById('submit');
var messages = document.getElementById('messages');
var conn = new WebSocket(endpoint);
var theForm = document.getElementById('theForm');

theForm.onsubmit = function() {
    return false;
};

conn.onopen = function(event) {
    console.log('Connected ...');
};

conn.onerror = function(event) {
    console.log(event);
}

conn.onmessage = function(event) {
    var txtbox = document.getElementById('txtbox');
    var newSpan = document.createElement('span');
    var newSpanTxt = document.createTextNode(event.data);
    
    console.log('Msg rcvd: ' + event.data);
    
    newSpan.setAttribute('class', 'someoneElse');
    newSpan.appendChild(newSpanTxt);
    messages.appendChild(newSpan);
};

submit.onclick = function(event) {
    var txtbox = document.getElementById('txtbox');
    if (!txtbox.value) {
        return;
    }
    
    conn.send(txtbox.value);
    console.log('Msg sent: ' + txtbox.value);
    
    var newSpan = document.createElement('span');
    var newSpanTxt = document.createTextNode(txtbox.value);
    
    newSpan.appendChild(newSpanTxt);
    newSpan.setAttribute('class', 'me');
    messages.appendChild(newSpan);
    
    txtbox.value = '';
};

