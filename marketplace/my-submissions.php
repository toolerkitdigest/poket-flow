<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('PoketFlow no longer accepts worker task submissions. Historical records are retained.');
