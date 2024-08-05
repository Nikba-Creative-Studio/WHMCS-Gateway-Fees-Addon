<?php

use WHMCS\Database\Capsule;

/**
 * Function to update the gateway fee for a specific invoice.
 *
 * @param array $vars
 */
function update_gateway_fee($vars)
{
    $id = $vars['invoiceid'];
    $invoice = Capsule::table('tblinvoices')->where('id', $id)->first();

    if (!$invoice) {
        return;
    }

    $paymentmethod = $invoice->paymentmethod;
    // Delete existing gateway fee items from the invoice
    Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->where('notes', 'gateway_fees')->delete();

    // Retrieve fee settings for the specific payment method
    $results = Capsule::table('tbladdonmodules')
        ->where('setting', 'like', 'fee_2_' . $paymentmethod)
        ->orWhere('setting', 'like', 'fee_1_' . $paymentmethod)
        ->get();

    $params = [];
    foreach ($results as $result) {
        $params[$result->setting] = $result->value;
    }

    $fee1 = $params['fee_1_' . $paymentmethod] ?? 0;
    $fee2 = $params['fee_2_' . $paymentmethod] ?? 0;
    $total = InvoiceTotal($id);
    $amountdue = 0;
    if ($total > 0) {
        $amountdue = $fee1 + $total * $fee2 / 100;
    }

    // Add gateway fee item to the invoice if applicable
    if ($amountdue > 0) {
        $userid = $invoice->userid;
        $description = getGatewayName2($paymentmethod) . " VAT (Fee: " . ($fee1 > 0 ? $fee1 : '') . ($fee2 > 0 ? "+" . $fee2 . "%" : "") . ")";
        Capsule::table('tblinvoiceitems')->insert([
            "userid" => $userid,
            "invoiceid" => $id,
            "type" => "Fee",
            "notes" => "gateway_fees",
            "description" => $description,
            "amount" => $amountdue,
            "taxed" => "0",
            "duedate" => date('Y-m-d'),
            "paymentmethod" => $paymentmethod
        ]);
    }
    updateInvoiceTotal($id);
}

/**
 * Function to update the gateway fee for all unpaid invoices of a specific client.
 *
 * @param int $userid
 * @param string $newPaymentMethod
 */
function update_gateway_fee_for_client($userid, $newPaymentMethod)
{
    // Update all unpaid invoices for the client with the new payment method
    $invoices = Capsule::table('tblinvoices')
        ->where('userid', $userid)
        ->where('status', 'Unpaid')
        ->get();

    foreach ($invoices as $invoice) {
        Capsule::table('tblinvoices')
            ->where('id', $invoice->id)
            ->update(['paymentmethod' => $newPaymentMethod]);

        update_gateway_fee(['invoiceid' => $invoice->id]);
    }
}

// Register hooks
add_hook("InvoiceCreationPreEmail", 1, "update_gateway_fee");
add_hook("InvoiceChangeGateway", 1, "update_gateway_fee");
add_hook("InvoiceCreated", 1, "update_gateway_fee");
add_hook("InvoiceCreationAdminArea", 1, "update_gateway_fee");
add_hook("InvoiceCreation", 1, "update_gateway_fee");

add_hook("ClientChangePaymentMethod", 1, function($vars) {
    $userid = $vars['userid'];
    $newPaymentMethod = $vars['newpaymentmethod'];
    update_gateway_fee_for_client($userid, $newPaymentMethod);
});

add_hook('AdminClientProfileTabFieldsSave', 1, function($vars) {
    $userid = $vars['userid'];
    $newPaymentMethod = Capsule::table('tblclients')->where('id', $userid)->value('defaultgateway');
    update_gateway_fee_for_client($userid, $newPaymentMethod);
});

/**
 * Function to calculate the total amount of an invoice.
 *
 * @param int $id
 * @return float
 */
function InvoiceTotal($id)
{
    global $CONFIG;
    $invoiceItems = Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->get();

    $taxsubtotal = 0;
    $nontaxsubtotal = 0;
    foreach ($invoiceItems as $item) {
        if ($item->taxed == "1") {
            $taxsubtotal += $item->amount;
        } else {
            $nontaxsubtotal += $item->amount;
        }
    }

    $subtotal = $total = $nontaxsubtotal + $taxsubtotal;
    $invoice = Capsule::table('tblinvoices')->where('id', $id)->first();
    $userid = $invoice->userid;
    $credit = $invoice->credit;
    $taxrate = $invoice->taxrate;
    $taxrate2 = $invoice->taxrate2;

    if (!function_exists("getClientsDetails")) {
        require_once (dirname(__FILE__) . "/clientfunctions.php");
    }

    $clientsdetails = getClientsDetails($userid);
    $tax = $tax2 = 0;
    if ($CONFIG['TaxEnabled'] == "on" && !$clientsdetails['taxexempt']) {
        if ($taxrate != "0.00") {
            if ($CONFIG['TaxType'] == "Inclusive") {
                $taxrate = $taxrate / 100 + 1;
                $calc1 = $taxsubtotal / $taxrate;
                $tax = $taxsubtotal - $calc1;
            } else {
                $taxrate = $taxrate / 100;
                $tax = $taxsubtotal * $taxrate;
            }
        }

        if ($taxrate2 != "0.00") {
            if ($CONFIG['TaxL2Compound']) {
                $taxsubtotal += $tax;
            }

            if ($CONFIG['TaxType'] == "Inclusive") {
                $taxrate2 = $taxrate2 / 100 + 1;
                $calc1 = $taxsubtotal / $taxrate2;
                $tax2 = $taxsubtotal - $calc1;
            } else {
                $taxrate2 = $taxrate2 / 100;
                $tax2 = $taxsubtotal * $taxrate2;
            }
        }

        $tax = round($tax, 2);
        $tax2 = round($tax2, 2);
    }

    if ($CONFIG['TaxType'] == "Inclusive") {
        $subtotal = $subtotal - $tax - $tax2;
    } else {
        $total = $subtotal + $tax + $tax2;
    }

    if (0 < $credit) {
        if ($total < $credit) {
            $total = 0;
        } else {
            $total -= $credit;
        }
    }

    $subtotal = format_as_currency($subtotal);
    $tax = format_as_currency($tax);
    $total = format_as_currency($total);
    return $total;
}

/**
 * Function to get the friendly name of a payment gateway.
 *
 * @param string $modulename
 * @return string
 */
function getGatewayName2($modulename)
{
    $result = Capsule::table('tblpaymentgateways')->where('gateway', $modulename)->where('setting', 'name')->first();
    return $result->value;
}

?>
