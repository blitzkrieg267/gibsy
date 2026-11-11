<?php
/*
Kreck — a Gibbon theme that reskins the open-source Gibbon SIS into the
proprietary KRECK SMS look (slate app-shell + teal accent), matching the
KRECK MicroLearn Moodle (LMS) theme so the two layers feel like one product.
Read by modules/System Admin/theme_manage_installProcess.php.
*/

$name        = 'Kreck';
$description = "KRECK SMS skin — deep-slate background with teal accent, matched to the KRECK MicroLearn LMS. Turns Gibbon into KRECK.";
$version     = '0.1.00';
$author      = 'KRECK MicroLearn';
$url         = 'https://krecke.example';
$responsive  = 'Y';

// Selectable accent colours (cosmetic; the Kreck skin themes the page shell itself).
$themeColours = [
    'teal'   => __('Teal'),
    'green'  => __('Green'),
    'blue'   => __('Blue'),
    'indigo' => __('Indigo'),
    'sky'    => __('Sky'),
];
