<?php
// Cards Against Lag — guest-play party game shell. All state lives client-side (a room
// code + secret token in localStorage); the game logic is server-authoritative via api.php.
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cards Against Lag · party game</title>
  <meta name="robots" content="noindex">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <meta property="og:title" content="Cards Against Lag">
  <meta property="og:description" content="A fill-in-the-blank party game you can play with friends online. Create a room, share the code, get funny.">
  <meta name="theme-color" content="#0a0d16">
  <link rel="stylesheet" href="cards.css">
</head>
<body>
  <div class="wrap">
    <div class="top">
      <span class="logo">Cards Against <b>Lag</b></span>
      <span class="sp"></span>
      <a class="pill" href="/">← logansandivar.com</a>
    </div>
    <div id="app"></div>
    <div class="foot">
      An unofficial, non-commercial fan game — not affiliated with Cards Against Humanity LLC.
      The “Uncut” deck uses card text from <a href="https://cardsagainsthumanity.com/" target="_blank" rel="noopener">Cards Against Humanity</a>
      under <a href="https://creativecommons.org/licenses/by-nc-sa/2.0/" target="_blank" rel="noopener">CC&nbsp;BY-NC-SA&nbsp;2.0</a>.
    </div>
  </div>
  <script src="cards.js"></script>
</body>
</html>
