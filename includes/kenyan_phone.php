<?php
/** Accept Kenyan mobile formats without a stale carrier-prefix allowlist. */
function kenyanMobileNumber(string $raw): string {
    $number = preg_replace('/[\s()+-]/', '', trim($raw));
    if (str_starts_with($number, '2540')) $number = '254' . substr($number, 4);
    elseif (preg_match('/^0[17]\d{8}$/D', $number)) $number = '254' . substr($number, 1);
    elseif (preg_match('/^[17]\d{8}$/D', $number)) $number = '254' . $number;
    if (!preg_match('/^254[17]\d{8}$/D', $number)) throw new InvalidArgumentException('Enter a valid Kenyan mobile number, for example 0712345678 or 0112345678.');
    return $number;
}
