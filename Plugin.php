<?php namespace Wpstudio\TBank;

use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use System\Classes\PluginBase;
use Wpstudio\TBank\Classes\DefaultMoneyRepair;
use Wpstudio\TBank\Classes\TBankCheckout;

class Plugin extends PluginBase
{
    /**
     * @var array Plugin dependencies
     */
    public $require = ['Offline.Mall'];

    public function boot()
    {
        $gateway = $this->app->get(PaymentGateway::class);
        $gateway->registerProvider(new TBankCheckout());
    }

    public function registerComponents()
    {
    }

    public function registerSettings()
    {
    }

}
