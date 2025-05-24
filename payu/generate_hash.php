<?php
/**
 * Generate a PayU SHA‐512 hash for the given fields.
 *
 * You must have exactly:
 *   key|txnid|amount|productinfo|firstname|email|udf1|…|udf10|salt
 *
 * @param string $txnid
 * @param float  $amount
 * @param string $productinfo
 * @param string $firstname
 * @param string $email
 * @return string  lowercase hex SHA‐512
 */
function generatePayuHash(string $txnid, float $amount, string $productinfo, string $firstname, string $email): string {
    $config = include __DIR__ . '/config.php';
    $key    = $config['key'];
    $salt   = $config['salt'];

    // core 6 fields
    $parts = [
      $key,
      $txnid,
      $amount,
      $productinfo,
      $firstname,
      $email
    ];

    // udf1 … udf10 as empty
    for ($i = 0; $i < 10; $i++) {
        $parts[] = '';
    }

    // finally the salt
    $parts[] = $salt;

    $hashString = implode('|', $parts);
    return strtolower(hash('sha512', $hashString));
}
