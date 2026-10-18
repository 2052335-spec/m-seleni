# M-seleni (demo e-wallet)

A demo e-wallet app. **All money is fake. Nothing here handles real payments or real personal data.**

Features: register and log in (username + 4-digit PIN), balance, top up, send money to another user, pay bills (Cashpower to Solomon Power, Cashwater to Solomon Water), and activity history.

There are two versions in this repository.

| Version | Where | What it is |
|---|---|---|
| **Prototype** | `index.html` (this folder) | Everything runs in the browser and is saved in that browser only. Works on GitHub Pages. |
| **Server version** | `php-app/` + `database/` | PHP on a web server, data in PostgreSQL on a separate database server. Accounts and balances are shared by every device. |

## Demo accounts

| Username | PIN  | Starting balance |
|----------|------|------------------|
| demo     | 1234 | 1,000 |
| maria    | 1111 | 500 |
| juan     | 2222 | 750 |

New accounts start with 500.

## Prototype (GitHub Pages)

Open `index.html` in a browser, or turn on **Settings > Pages > Deploy from a branch > main / (root)**.
Data is stored in each browser's `localStorage`, so it is not shared between devices.

## Server version

```
 Browser  --->  Web server VM (Apache + PHP)  --->  Database VM (PostgreSQL)
              php-app/index.html + api.php            database: mseleni_db
```

Files:

- `php-app/index.html` is the page. It only shows things; it keeps no balances.
- `php-app/api.php` is the JSON API. It checks everything and talks to PostgreSQL.
- `php-app/db.php` opens the database connection.
- `php-app/config.sample.php` is copied to `config.php` on the web server and holds the database address and password. **`config.php` is never uploaded to GitHub** (see `.gitignore`).
- `database/schema.sql` creates the tables and the demo data.

### How the database is organised

- `users`: username, name, bcrypt-hashed PIN, failed-attempt counter
- `wallets`: one balance per user, with `CHECK (balance >= 0)`
- `billers`: Cashpower and Cashwater (adding a biller is one `INSERT`)
- `transactions`: the ledger, one row per top up, transfer or bill payment

### What the server does for safety

- PINs are stored as bcrypt hashes, never as text.
- 5 wrong PINs lock the account for 5 minutes.
- Every query uses prepared statements (no SQL injection).
- A transfer takes the money, adds it to the other wallet and writes the ledger row in **one database transaction**. If anything fails, nothing changes.
- Wallet rows are locked while a transfer runs, so two payments at the same moment cannot spend the same money.
- The web app's database user can read and write data but cannot delete transactions or change tables.
- Only JSON requests are accepted, which blocks cross-site request forgery.

### Honest limits

This is a class demo. A 4-digit PIN is weak by design, there is no email or phone check, and nothing is audited. Do not use it for real money.

## Setup

See the step-by-step guide your teacher or Claude gave you. In short:

1. Database VM: create the user `mseleni_app`, create `mseleni_db`, load `database/schema.sql`, allow the web VM in `pg_hba.conf`.
2. Web VM: install `php`, `php-pgsql` and Apache, copy `php-app/` to `/var/www/html/mseleni/`, copy `config.sample.php` to `config.php`, and edit it.
3. Open `http://WEB-VM-IP/mseleni/` in a browser.
