<?php
require __DIR__ . '/lib/owner_auth.php';
owner_logout();
header('Location: /owner/login.php');
exit;
