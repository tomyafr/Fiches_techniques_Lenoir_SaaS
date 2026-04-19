<?php
$password = 'password123';
$hash = '$2y$12$Es7rLPLN9fKc7k6mxeGtVurg3.0nPqbb6.EJmJ6x/XkI7t1LFPFAC';

if (password_verify($password, $hash)) {
    echo "Password IS correct for this hash.\n";
} else {
    echo "Password IS NOT correct for this hash.\n";
    echo "New hash for 'password123': " . password_hash($password, PASSWORD_BCRYPT) . "\n";
}
