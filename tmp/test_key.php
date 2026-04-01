<?php
$b64 = 'Z3NrX3l0Vmp4bmJiRnNwZEdjMm53Q2dvV0dyeWIzRllpNDRNTG1zWWpwRmpwdlRFNzZPQmt5SDI=';
$key = base64_decode($b64);
echo "Key: " . $key . "\n";
echo "Length: " . strlen($key) . "\n";
?>
