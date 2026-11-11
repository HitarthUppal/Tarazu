#!/bin/bash
# Local stand-in for sendmail: appends each message to /tmp/tz-local/mail.txt
cat >> /tmp/tz-local/mail.txt; echo "=====END=====" >> /tmp/tz-local/mail.txt
