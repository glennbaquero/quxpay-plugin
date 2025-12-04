# QUX Pay® Wordpress
A custom payment gateway plugin for WooCommerce that enables secure payment processing for your online store.

### Requirements
- WordPress 5.6 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- SSL certificate (required for live payments)
- [Payment Provider] Merchant account in Qux

### Installation
- Log in to your WordPress admin panel
- Navigate to Plugins > Add New
- Search for "WooCommerce Payment Gateway"
- Click Install Now and then Activate

### Configuration
- Navigate to WooCommerce > Settings > Payments
- Find the Qux Payment in the list and click Set up
- Configure the following settings:

| Setting    | Description | Required 
| -------- | ------- | ------ |
| Enable/Disable  | Enable this payment method    | Yes
| Title | Payment method title shown to customers | Yes
| Description | Payment method description | No
| Test Mode | Enable for testing (uses sandbox API) | No
| Success URL | Success URL upon successful payment in QuxPay. | Yes
| App Key | APP Key can get from https://qux.tv/wallet/balance and click Qux API Key to generate. | Yes
| Secret Key | Secret Key can get from https://qux.tv/wallet/balance and click Qux API Key to generate. | Yes


# How to Obtain Your API Key and Secret

This guide will walk you through the steps to obtain your API key and secret for integrating our service with your website.

## Step 1: Sign Up or Log In

1. Visit our [website](https://quxpay.com/).
2. If you don’t have an account, click on the “Sign Up” button and fill out the registration form. If you already have an account, click on “Log In” and enter your credentials.

## Step 2: Navigate to the Biller API Section

1. Once logged in, go to **Biller API** icon.
2. In the dashboard, find and click on the “API” tab located in the sidebar menu.

## Step 3: Generate Your API Key and Secret

1. In the **Biller API** section, you need to accept our **Terms and Condition**.
2. after accepting our **Terms and Condition** you are able to generate your own key.
3. Click the “Generate New API Key & Passphrase” button.

## Step 4: Copy Your API Key and Secret

1. After generating your API key, system will display your new API key and secret.
2. Copy these credentials and store them in a secure place.

## Step 5: Configure Your Website

1. Paste the API key and secret into the corresponding fields.

## Step 6: Test the Integration

1. After saving the configuration, test the integration by making a simple request to ensure everything is set up correctly.
2. Refer to our documentation for details on making a requests.

## Support

If you encounter any issues or have questions, please reach out to our support team at [support@qux.tv](mailto:support@qux.tv).
