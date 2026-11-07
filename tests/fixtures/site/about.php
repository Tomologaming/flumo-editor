<?php
declare(strict_types=1);
$siteName = 'Musterfirma';
$year = date('Y');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Über uns – <?= htmlspecialchars($siteName) ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <main class="container">
    <h1>Über uns</h1>
    <p>Wir sind ein kleines Team.</p>
    <p>Jahr: <?= $year ?></p>
  </main>
</body>
</html>
