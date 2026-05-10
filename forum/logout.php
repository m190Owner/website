<?php
require_once __DIR__ . '/includes/bootstrap.php';
doLogout();
header('Location: /forum/login.php');
exit;
