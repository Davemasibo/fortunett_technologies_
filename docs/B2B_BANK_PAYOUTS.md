# B2B bank payouts

Tenant bank details are stored in the existing `payment_gateways.credentials` field, encrypted with the application gateway key. In **Settings → Payments**, the tenant saves the bank name, account name, account number, and the receiver shortcode Safaricom has provided for that bank route, then explicitly selects **Use this bank account for automated M-Pesa B2B payouts**. The account number is sent as Daraja `AccountReference`; it is masked in the payout ledger.

The B2B sender uses the platform Daraja consumer key/secret, initiator and security credential already stored for platform payouts. The B2B sender shortcode is `platform_mpesa_config.b2b_shortcode`, falling back to the existing B2C shortcode when omitted. These secrets are used server-side and are never returned by tenant endpoints. B2B callback URLs use a random per-installation token stored in `platform_settings.b2b_callback_token`.

## Enable after Safaricom approval

1. Apply for B2B activation for the platform sender shortcode. Safaricom requires KYC and the receiving organization shortcode(s) to be listed/approved. Confirm with Safaricom the bank receiver shortcode, `BusinessPayBill` command, and account-reference format for each bank. The receiving bank shortcode must be whitelisted by Safaricom.
2. Set the B2B sender shortcode if it differs from the platform B2C shortcode:

   ```powershell
   php tools/payout_config.php --b2b-shortcode=123456
   ```

3. Confirm `platform_settings.public_base_url` is the public HTTPS site, the Daraja environment and initiator credentials are production-ready, and the platform payout switch is enabled. B2B callbacks are `/api/payment/b2b_result.php` and `/api/payment/b2b_timeout.php` with a private token query parameter.
4. Enable bank B2B routing only after Safaricom has activated the source shortcode and receiver routing:

   ```powershell
   php tools/payout_config.php --enable-b2b
   ```

5. A super administrator reviews the tenant’s bank destination and marks it verified on **Super Admin → Automated payouts**. Editing tenant bank details clears that verification. Payouts to bank use the existing queue and reservation ledger.

Daraja acceptance is not a completed transfer. Only a success result callback settles the queue and payment balances. Transport failures and timeouts remain `unknown`, keep the queue reserved, and block another payout for that tenant. Review them against the M-Pesa statement on **Automated payouts**; mark paid with the receipt or release the queue only after confirming the transfer did not occur. Reconciliation decisions are recorded with the administrator, evidence, and timestamp.

The B2B API is for transfers between business accounts; it does not replace C2B or STK Push for customers paying an ISP. See [Safaricom B2B terms](https://www.safaricom.co.ke/images/Downloads/M-PESA-B2B-Terms-and-Conditions-2025.pdf), [B2B application](https://www.safaricom.co.ke/images/Downloads/M-PESA-B2B-Application-Form-2025.pdf), and the [Daraja portal](https://developer.safaricom.co.ke/apis/B2BExpressCheckout).
