<?php
http_response_code(503);             // Service Unavailable
header('Retry-After: 3600');         // Ask clients to retry in 1 hour
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Scheduled Maintenance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <div class="text-center">
    <h1 class="display-4 mb-3">🛠 Scheduled Maintenance</h1>
    <p class="lead">Our site is currently undergoing scheduled maintenance.<br>Please check back soon.</p>
  </div>
</body>
</html>
<?php exit; ?>
