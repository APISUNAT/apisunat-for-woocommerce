<?php
namespace Atm\Apisunatwp;

use Atm\Apisunatwp\Jobs\SendOrderJob;
use Atm\Apisunatwp\Jobs\StatusCheckJob;

class Deactivator {

    public static function deactivate(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SendOrderJob::ACTION,    [], SendOrderJob::GROUP);
            as_unschedule_all_actions(StatusCheckJob::ACTION,  [], StatusCheckJob::GROUP);
        }
    }
}
