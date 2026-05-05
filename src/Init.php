<?php
namespace Atm\Apisunatwp;

use Atm\Apisunatwp\Admin\AdminAssets;
use Atm\Apisunatwp\Admin\LogViewer;
use Atm\Apisunatwp\Admin\OrderActions;
use Atm\Apisunatwp\Admin\OrderColumn;
use Atm\Apisunatwp\Admin\OrderFilters;
use Atm\Apisunatwp\Admin\OrderMetaBox;
use Atm\Apisunatwp\Admin\ProductFields;
use Atm\Apisunatwp\Admin\SettingsPage;
use Atm\Apisunatwp\Hooks\CheckoutHooks;
use Atm\Apisunatwp\Hooks\OrderHooks;
use Atm\Apisunatwp\Jobs\StatusCheckJob;

class Init {

    public static function run(): void {
        OrderHooks::register();
        CheckoutHooks::register();
        StatusCheckJob::register();

        SettingsPage::init();
        LogViewer::register();
        OrderColumn::register();
        OrderMetaBox::register();
        OrderActions::register();
        OrderFilters::register();
        AdminAssets::register();
        ProductFields::register();
    }
}
