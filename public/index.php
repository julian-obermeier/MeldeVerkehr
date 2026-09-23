<?php

declare(strict_types=1);

use MeldeVerkehr\Http\Request;

$app = require dirname(__DIR__) . '/bootstrap/app.php';

require dirname(__DIR__) . '/routes/web.php';

$app->run(Request::fromGlobals());
