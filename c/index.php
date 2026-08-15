<?php
// PUBLIC canary-link handler. /c/{id} (rewritten from .htaccess) → record the trip,
// then serve a generic 404 so the tripper sees nothing but a dead link. An unknown
// id behaves identically, so the existence of the canary system is never revealed.
require __DIR__ . '/../owner/lib/tokens.php';

$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['t'] ?? ''));
if ($id !== '') token_record_hit($id, token_client_meta());   // best-effort, never throws

// Plausible dead-end. Real Apache 404 wording, no hint that anything was logged.
http_response_code(404);
header('Content-Type: text/html; charset=iso-8859-1');
header('Cache-Control: no-store');
?><!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL was not found on this server.</p>
</body></html>
