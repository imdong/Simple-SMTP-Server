#!/bin/bash
nohup php smtp_init.php > runStart.log 2>&1 &
echo "Start Ok!";
