# WHMCS Gateway Fees Addon

This WHMCS addon allows you to add fees based on the payment gateway being used. It supports applying fees to invoices, updating fees when payment methods are changed, and handling changes made by both clients and administrators.

## Features

- Add fixed and percentage-based fees for each payment gateway.
- Automatically apply fees to invoices when they are created or updated.
- Update fees when the payment method is changed by the client or administrator.

## Installation

1. **Download and Extract:**
   Download the addon files and extract them to your WHMCS installation directory.

2. **Upload Files:**
   Upload the `gateway_fees.php` file to `modules/addons/gateway_fees/`.
   Upload the `hooks.php` file to `modules/addons/gateway_fees/hooks/`.

3. **Activate Addon:**
   - Login to your WHMCS admin area.
   - Navigate to `Setup` -> `Addon Modules`.
   - Find the "Gateway Fees" addon and click `Activate`.
   - Configure the addon settings as needed.

## Configuration

Once activated, you can configure the fees for each payment gateway in the addon settings. There are two types of fees you can configure:
- **Fixed Fee:** A fixed amount that will be added to the invoice.
- **Percentage Fee:** A percentage of the total invoice amount that will be added as a fee.

## Usage

The addon will automatically apply the configured fees based on the payment gateway used for the invoice. It will also update the fees if the payment method is changed by the client or administrator.

## Support

For support or questions, please open an issue on the GitHub repository.

## License

This addon is open-source and licensed under the MIT License.
