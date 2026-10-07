<?php
// Deliberately public: installers test DNS and HTTPS without transmitting credentials.
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo 'fortunett-wan-ok';
