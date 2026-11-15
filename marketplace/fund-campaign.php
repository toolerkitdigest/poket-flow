<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('Worker task campaigns are no longer offered. Use the advertiser dashboard to create advertising placements.');
