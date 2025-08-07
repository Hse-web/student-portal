<?php
// dashboard/student/some_feature.php

// Replace the rest of this file’s content with:
http_response_code(503);             // “Service Unavailable”
header('Retry-After: 3600');         // ask clients to retry in 1 hour
?>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Technical Glitch</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <div class="text-center">
    <h1 class="display-4 mb-3">🚧 Technical Glitch</h1>
    <p class="lead">
      Our team is currently addressing a technical issue with the payment page.<br>  
      Thank you for your patience—<br>we’ll be back online shortly.  
      Please check back soon!
    </p>
    <a href="/artovue/dashboard/student/index.php" class="btn btn-primary mt-2">
      ← Return to Dashboard
    </a>
  </div>
</body>
</html>
<?php
exit;  // prevent any further code below from running
?>
