<?php
$params = $_GET;
$params['page'] = 'blocked';
header('Location: index.php?' . http_build_query($params));
exit;
