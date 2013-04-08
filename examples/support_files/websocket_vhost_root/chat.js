// Please excuse my ugly javascript.

var submit = document.getElementById('submit');
var messages = document.getElementById('messages');
var conn = new WebSocket('ws://websockets.myhost:1337/chat');
var theForm = document.getElementById('theForm');

theForm.onsubmit = function() {
    return false;
};

conn.onopen = function(event) {
    console.log('Connected ...');
};

conn.onmessage = function(event) {
    var txtbox = document.getElementById('txtbox');
    var newSpan = document.createElement('span');
    var newSpanTxt = document.createTextNode();
    
    console.log('Message received: ' + event.data);
    
    newSpan.setAttribute('class', 'someoneElse');
    newSpanTxt.data = event.data;
    newSpan.appendChild(newSpanTxt);
    messages.appendChild(newSpan);
};

submit.onclick = function(event) {
    var txtbox = document.getElementById('txtbox');
    var newSpan = document.createElement('span');
    var txt = document.createTextNode();
    
    if (!txtbox.value) {
        return;
    }
    
    if (conn.send(txtbox.value)) {
        console.log('Message sent: ' + txtbox.value);
        
        txt.data = txtbox.value;
        txtbox.value = '';
        newSpan.appendChild(txt);
        newSpan.setAttribute('class', 'me');
        messages.appendChild(newSpan);
    } else {
        console.log('Message send error :(');
    }
};

