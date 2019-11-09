#!/usr/bin/env bash
openssl req -config local.conf -new -x509 -sha256 -newkey rsa:2048 -nodes -keyout localhost.key.pem -days 3650 -out localhost.cert.pem

cat localhost.cert.pem localhost.key.pem > localhost.pem
