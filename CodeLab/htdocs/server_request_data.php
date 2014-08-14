<?php

require_once '../phplib/ApiServer.php';

/**
 * Dispatches an HTTP request for data to an ApiServer instance.
 */

$server = new ApiServer();
$data = $server->handleDataRequest($_GET);
echo json_encode($data);
