<?php
/*
KRECK (KRECK MicroLearn) — standalone bootstrap-failure page.
Based on Gibbon (https://gibbonedu.org), licensed under the GNU GPL v3; the
original copyright notice is retained for GPL compliance. The rendered page is
KRECK-branded. See modules/Kreck Bridge for the KRECK plugin.

Gibbon copyright © 2010, Gibbon Foundation — GNU General Public License v3 or later.
*/
?>
<!DOCTYPE html>
<html lang="">
    <head>
        <title>KRECK</title>

        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta http-equiv="content-type" content="text/html; charset=utf-8"/>
        <meta name="robots" content="noindex"/>
        <link rel="shortcut icon" type="image/x-icon" href="./favicon.ico"/>

        <link rel="stylesheet" href="./themes/Kreck/css/main.css" type="text/css" media="all">
        <link rel="stylesheet" href="./resources/assets/css/core.min.css" type="text/css" media="all">
        <link rel="stylesheet" href="./resources/assets/css/theme.min.css" type="text/css" media="all">
    </head>

    <body class="h-screen flex flex-col font-sans m-0 p-0"
          style="background: linear-gradient(135deg, #0f2233 0%, #14384a 55%, #0e6b63 100%) no-repeat fixed;">

        <div class="px-4 sm:px-6 lg:px-12 pb-24">
            <div id="header" class="relative flex justify-between items-center">
                <a id="header-logo" class="block my-4 leading-none" href="<?php echo (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['PHP_SELF'] ?? ''); ?>">
                    <img class="block my-4" alt="KRECK MicroLearn" src="./themes/Kreck/img/logo.png" style="max-height:56px;background:#ffffff;border-radius:10px;padding:6px 10px;">
                </a>
            </div>
        </div>

        <div id="wrapOuter" class="flex-1 pt-24">
            <div id="wrap" class="px-0 sm:px-6 lg:px-12 -mt-48">
                <div id="content-wrap" class="relative w-full flex">
                    <div id="content" class="max-w-full w-full shadow bg-white sm:rounded px-8 pt-4 pb-8">
                        <h1 style="color:#0f2233;border-bottom:3px solid #0e9f9a;padding-bottom:8px;">
                            <?php echo !empty($error) ? htmlspecialchars($error) : 'Something went wrong'; ?>
                        </h1>
                        <p style="color:#334155;font-size:15px;line-height:1.6;margin-top:14px;">
                            <?php echo !empty($message) ? htmlspecialchars($message) : 'We could not load that page. This usually means a mistyped address, or a page you do not have permission to view. Please check the address, or contact your KRECK administrator if the problem continues.'; ?>
                        </p>
                        <p style="margin-top:18px;">
                            <a href="./" style="display:inline-block;background:#0e9f9a;color:#fff;text-decoration:none;border-radius:6px;padding:9px 16px;font-weight:600;">Back to KRECK</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
