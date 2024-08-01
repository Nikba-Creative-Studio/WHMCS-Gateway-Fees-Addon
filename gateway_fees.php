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
    $configarray = [
        "name" => "Gateway Fees",
        "description" => "Add fees based on the gateway being used.",
        "version" => "1.0.1",
        "author" => "Bargan Nicolai"
    ];

    // Retrieve all distinct payment gateways
    $gateways = Capsule::table('tblpaymentgateways')->groupBy('gateway')->get();

    // Add configuration fields for each gateway
    foreach ($gateways as $gateway) {
        $configarray['fields']["fee_1_" . $gateway->gateway] = [
            "FriendlyName" => $gateway->gateway,
            "Type" => "text",
            "Default" => "0.00",
            "Description" => "$"
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
