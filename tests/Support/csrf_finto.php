<?php

// Nelle prove delle viste le funzioni globali del portale (functions.php) non sono caricate: questo è il campo CSRF di prova.
if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        echo '<input type="hidden" name="csrf_token" value="prova">';
    }
}
