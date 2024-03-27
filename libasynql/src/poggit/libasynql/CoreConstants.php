<?php

declare(strict_types=1);

namespace poggit\libasynql;

use function define;
use function defined;
use function dirname;
use function var_dump;

// composer autoload doesn't use require_once and also pthreads can inherit things
if (defined('libasynql\_CORE_CONSTANTS_INCLUDED')) {
	return;
}
define('libasynql\_CORE_CONSTANTS_INCLUDED', true);

define('libasynql\COMPOSER_AUTOLOADER_PATH', dirname(__FILE__, 7) . '/autoload.php');