<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

/**
 * Configuration settings for the Gateway Fees addon.
 *
 * @return array
 */
function gateway_fees_config()
{
    // Get the system currency
    $currencySymbol = Capsule::table('tblcurrencies')
        ->where('default', 1)
        ->value('code');

    $configarray = [
        "name" => "Gateway Fees",
        "description" => "Add fees based on the gateway being used.",
        "version" => "1.2",
        "author" => "Nikba Creative Studio",
        "fields" => [
            "enable_checkout_hook" => [
                "FriendlyName" => "Enable displaying Fees on the checkout page",
                "Type" => "yesno",
                "Default" => "yes", // Default to enabled
                "Description" => "Tick to enable displaying fees on the checkout page. Only for twenty-one Theme.",
            ]
        ],
    ];

    // Retrieve all distinct payment gateways
    $gateways = Capsule::table('tblpaymentgateways')->groupBy('gateway')->get();

    // Add configuration fields for each gateway
    foreach ($gateways as $gateway) {
        $configarray['fields']["fee_1_" . $gateway->gateway] = [
            "FriendlyName" => $gateway->gateway,
            "Type" => "text",
            "Default" => "0.00",
            "Description" => $currencySymbol
        ];
        $configarray['fields']["fee_2_" . $gateway->gateway] = [
            "FriendlyName" => $gateway->gateway,
            "Type" => "text",
            "Default" => "0.00",
            "Description" => "%<br />"
        ];
    }

    return $configarray;
}

/**
 * Activation function for the Gateway Fees addon.
 */
function gateway_fees_activate()
{
    // Retrieve all distinct payment gateways
    $gateways = Capsule::table('tblpaymentgateways')->groupBy('gateway')->get();

    // Insert default fee settings for each gateway into the addon modules table
    foreach ($gateways as $gateway) {
        Capsule::table('tbladdonmodules')->insert([
            ['module' => 'gateway_fees', 'setting' => 'fee_1_' . $gateway->gateway, 'value' => '0.00'],
            ['module' => 'gateway_fees', 'setting' => 'fee_2_' . $gateway->gateway, 'value' => '0.00']
        ]);
    }
}

?>
