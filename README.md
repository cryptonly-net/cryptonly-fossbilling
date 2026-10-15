<img src="https://raw.githubusercontent.com/cryptonly-net/.github/main/profile/assets/header-banner.svg" alt="Cryptonly" width="100%" />

<div align="center">

# Cryptonly for FOSSBilling

</div>

Accept cryptocurrency payments in FOSSBilling with Cryptonly. Customers pay on a hosted checkout page. FOSSBilling marks the invoice paid only after Cryptonly confirms the transfer with a signed webhook.

Your prices stay in the invoice currency. At checkout, Cryptonly converts the amount still due to the cryptocurrency the customer selects, using the live rate. Bitcoin, Ethereum, USDT, USDC, and the other assets enabled on your Cryptonly account are available without a plugin update.

## Features

- Hosted checkout page
- No verification required to start
- Fiat pricing. The invoice stays in its currency. The crypto amount is calculated at checkout
- Partials. A short payment is added as account credit and the invoice stays unpaid. The next checkout charges what is still due
- Sandbox. Test mode uses the Cryptonly sandbox API with testnet chains
- Fees. 0.5% per transaction. No setup fee and no monthly platform fee

## Requirements

- FOSSBilling 0.7 or newer
- A public HTTPS FOSSBilling URL. Cryptonly does not follow redirects
- A [Cryptonly merchant account](https://cryptonly.net)
- An API key with `accounts.list`, `invoices.create`, and `invoices.list` scopes
- The Account ID and webhook signing key from Cryptonly admin

## Install

1. Download the Cryptonly FOSSBilling adapter zip.
2. Extract it in the FOSSBilling root, so this file exists:

   `library/Payment/Adapter/Cryptonly/Cryptonly.php`

3. Open **System → Payment gateways → New payment gateway** and install Cryptonly.
4. Enter the API key, Account ID, and webhook signing key. Leave a secret blank on a later save to keep the stored value.
5. Enable the gateway. Turn on **Test mode** only when the credentials are from the Cryptonly sandbox. Use sandbox credentials with test mode, and production credentials with test mode off.

The adapter sends the webhook URL with each invoice, so you do not paste it into the Cryptonly dashboard.

## Payment behavior

- Checkout creates a fiat Cryptonly invoice for the amount still due after account credit, and the customer pays on the hosted page.
- `paid` and `overpaid` add the amount still due as account credit, then pay the invoice from that credit. An overpay is not stored as extra credit.
- `partially_paid` adds `settlement.amountPaid` as account credit and leaves the invoice unpaid. The next checkout starts a new Cryptonly invoice for what is still due (`FOSS-12`, then `FOSS-12-2`).
- `expired`, `cancelled`, `failed`, `created`, and `processing` do not add credit. A later `paid` still can.
- `suspended` does not add credit.
- The browser return from the hosted page does not mark the invoice paid.

Subscriptions are off. A renewal invoice is paid with a new checkout.

## Support

A [Cryptonly merchant account](https://cryptonly.net) is required.

Questions? [Contact our team](https://cryptonly.net/contact/) or write to [support@cryptonly.net](mailto:support@cryptonly.net).

## Development

```bash
php scripts/smoke-contract.php
bash scripts/build-release.sh
```

The release zip is written to `dist/`. That directory is not part of the source tree.

## License

[MIT](LICENSE)
