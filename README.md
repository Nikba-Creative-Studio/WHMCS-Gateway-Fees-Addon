# WHMCS Gateway Fees Addon

This WHMCS addon allows you to add fees based on the payment gateway being used. It supports applying fees to invoices, updating fees when payment methods are changed, and handling changes made by both clients and administrators.

## You like this module? [Buy me a Coffee](https://buymeacoffee.com/nikba) ☕︎

## Features

- Add fixed and percentage-based fees for each payment gateway.
- Automatically apply fees to invoices when they are created or updated.
- Update fees when the payment method is changed by the client or administrator.

## Installation

1. **Download the addon:**
   - Clone the repository or download the ZIP file and extract it.

   ```bash
   git clone https://github.com/Nikba-Creative-Studio/WHMCS-Gateway-Fees-Addon.git
   ```

2. **Upload the addon:**
   - Upload the `gateway_fees` folder to the `modules/addons/` directory of your WHMCS installation.

3. **Activate the addon:**
   - Log in to your WHMCS admin panel.
   - Go to **Setup** > **Addon Modules**.
   - Locate **Gateway Fees** and click the **Activate** button.

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

## Version 1.2 - Changelog

### Updates and Improvements

1. **Currency Display Fix**:
   - The module now correctly displays fees in the default currency set in the WHMCS system. This fix ensures that all fees are shown in the appropriate currency without needing manual adjustments.

2. **Fee Display on Checkout Page for Twenty-One Template**:
   - Fees associated with payment methods configured via this module are now displayed on the checkout page when using the default Twenty-One template.
   - **Note for Developers**: If you are using a custom template, you might need to make adjustments to the `ShoppingCartCheckoutOutput` hook in the `hooks.php` file to ensure that fees are displayed correctly on the checkout page.
