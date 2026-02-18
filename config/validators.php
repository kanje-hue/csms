<?php

/**
 * Input Validation and Sanitization Helper Functions
 */

/** 
 * Validate email address.
 * 
 * @param string $email
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** 
 * Validate if the value is a number.
 * 
 * @param mixed $value
 * @return bool
 */
function validateNumber($value) {
    return is_numeric($value);
}

/** 
 * Validate if the string meets certain criteria.
 * 
 * @param string $string
 * @param int $minLength
 * @return bool
 */
function validateString($string, $minLength = 1) {
    return is_string($string) && strlen($string) >= $minLength;
}

/** 
 * Sanitize input by stripping unwanted characters.
 * 
 * @param mixed $input
 * @return string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

?>