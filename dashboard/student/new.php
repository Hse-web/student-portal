<?php
// dashboard/student/some_feature.php

// Replace the rest of this file’s content with:
http_response_code(503);             // “Service Unavailable”
header('Retry-After: 3600');         // ask clients to retry in 1 hour
?>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Coming Soon</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <div class="text-center">
    <h1 class="display-4 mb-3">🚧 Coming Soon</h1>
    <p class="lead">We’re working on this feature — check back soon!</p>
    <a href="/artovue/dashboard/student/index.php" class="btn btn-primary mt-2">
      ← Return to Dashboard
    </a>
  </div>
</body>
</html>
<?php
exit;  // prevent any further code below from running
