<?php
http_response_code(404);
header('X-Robots-Tag: noindex, nofollow');
echo 'Not found';
