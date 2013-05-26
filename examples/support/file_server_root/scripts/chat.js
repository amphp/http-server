// Please excuse my ugly javascript.

var endpoint = 'ws://66.57.216.51:80/chat'; // address of your websocket endpoint here
//var endpoint = 'ws://127.0.0.1:1337/chat'; // address of your websocket endpoint here
var submit = document.getElementById('submit');
var messages = document.getElementById('messages');
var conn = new WebSocket(endpoint);
var theForm = document.getElementById('theForm');

conn.onopen = function(event) {
    console.log('Connected ...');
};

conn.onerror = function(event) {
    console.log(event);
}

conn.onmessage = function(event) {
    var dataType = event.data[0];
    var data = event.data.substring(1);
    
    console.log('Msg rcvd: ' + data);
    
    if (dataType == '0') {
        var userCount = document.getElementById('userCount');
        var newUserCountValue = document.createTextNode('Connected users: ' + data);
        
        while (userCount.childNodes.length >= 1) {
            userCount.removeChild(userCount.firstChild);
        }
        
        userCount.appendChild(newUserCountValue);
        
    } else {
        var txtbox = document.getElementById('txtbox');
        var newSpan = document.createElement('span');
        var newSpanTxt = document.createTextNode(data);
        
        newSpan.setAttribute('class', 'someoneElse');
        newSpan.appendChild(newSpanTxt);
        messages.appendChild(newSpan);
    }
};

var submission = function(event) {
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
    
    return false;
};

submit.onclick = submission;
theForm.onsubmit = submission;
