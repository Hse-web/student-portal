<?php
// payu/config.php

return [
    'key' => 'z02L8J',  // PayU test key
    'salt' => 'ZoDYBzHABZmmLjdJADekp0yL848B0vFT',
    'base_url' => 'https://test.payu.in/_payment',
    'success_url' => 'http://localhost/student-portal/dashboard/payu/payment_success.php',
    'failure_url' => 'http://localhost/student-portal/dashboard/payu/payment_failure.php'
];
