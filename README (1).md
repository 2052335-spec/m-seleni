# M-seleni (demo e-wallet)

A clickable prototype of an e-wallet app. **Demo only: all money is fake and nothing leaves your browser.**

## Features
- Register and log in (username + 4-digit PIN)
- View balance (with show/hide)
- Top up with fake cash
- Send money to another M-seleni user, with a receipt
- Pay bills: **Cashpower** (Solomon Power, prepaid electricity) and **Cashwater** (Solomon Water). Enter a meter or account number and an amount, and get a receipt. Cashpower receipts show a made-up demo token that does not load any real meter.
- Activity history (top ups, transfers and bill payments)

## Demo accounts
| Username | PIN  | Starting balance |
|----------|------|------------------|
| demo     | 1234 | ₱1,000 |
| maria    | 1111 | ₱500 |
| juan     | 2222 | ₱750 |

New accounts start with ₱500. Use "Reset demo data" on the login screen to start over.

## Run it
Just open `index.html` in a browser. No install needed.

## Put it online with GitHub Pages
1. Create a new repository on GitHub and upload `index.html` and `README.md`.
2. Go to **Settings → Pages**.
3. Under **Build and deployment**, choose **Deploy from a branch**, pick `main` and `/ (root)`, then Save.
4. After a minute your app is live at `https://YOUR-USERNAME.github.io/YOUR-REPO/`.

## Notes
- Data is stored in each browser's `localStorage`, so accounts are not shared between devices or people.
- PINs are stored in plain text because this is a prototype. A real version needs a back end (PHP + PostgreSQL), hashed PINs, and database transactions for transfers. That is the next phase.
