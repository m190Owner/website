<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
// This public landing page deliberately does not look up or render private event data.
$query=$_SERVER['QUERY_STRING']??'';
$id=preg_match('/^id=([1-9][0-9]{0,15})$/D',$query,$match)&&strlen($match[1])<=16&&(float)$match[1]<=9007199254740991?$match[1]:null;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gather event</title><link rel="stylesheet" href="event.css"></head>
<body><main><div class="brand" aria-hidden="true">g.</div><h1>Open this event in Gather</h1><p>Event details are private. A link does not grant server membership or event access.</p><?php if($id!==null): ?><p><a class="launch" href="gather-event://event/<?= $id ?>">Open Gather for Windows</a></p><p class="note">Requires an installed Gather 0.224.0 or newer. Your browser may ask permission to open Gather. Sign in, then choose Open event link in Gather’s title bar.</p><?php endif; ?><p>Using portable Gather, an older version, or the button did not work? Paste the link instead:</p><ol><li>Open Gather 0.223.0 or newer and sign in.</li><li>Open the menu beside your server name or Direct Messages, then choose <strong>Open event link</strong>.</li><li>Copy this page’s full address and paste it into Gather.</li></ol><p>Links posted in Gather chat open the event directly. Opening an event never joins a call automatically.</p><a href="download.php">Download or update Gather for Windows</a><p class="note">Already a member? If the event is unavailable, its host may have changed access or deleted it. This page does not reveal whether an event exists.</p></main></body></html>
