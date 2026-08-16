<?php
require __DIR__ . '/lib/osint_auth.php';
osint_logout();
header('Location: /osint/login.php');
exit;
