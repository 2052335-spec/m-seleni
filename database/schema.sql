-- M-seleni demo e-wallet: PostgreSQL schema
-- Run once as the postgres superuser against an EMPTY database, e.g.:
--   sudo -u postgres psql -d mseleni_db < schema.sql
-- The role mseleni_app must already exist (see the setup guide).
-- All money is fake. This is a class demo, not a real payment system.

BEGIN;

-- ---------------------------------------------------------------
-- Tables
-- ---------------------------------------------------------------

CREATE TABLE users (
    id              BIGSERIAL PRIMARY KEY,
    username        VARCHAR(16)  NOT NULL UNIQUE
                    CHECK (username ~ '^[a-z0-9_]{3,16}$'),
    full_name       VARCHAR(30)  NOT NULL CHECK (length(btrim(full_name)) > 0),
    pin_hash        TEXT         NOT NULL,              -- bcrypt hash, never the PIN itself
    failed_attempts INTEGER      NOT NULL DEFAULT 0,
    locked_until    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- One wallet per user. The CHECK is the last line of defence:
-- even a bug in the PHP code cannot push a balance below zero.
CREATE TABLE wallets (
    user_id  BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    balance  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (balance >= 0)
);

-- Things users can pay. Adding a biller later is just one INSERT.
CREATE TABLE billers (
    code         VARCHAR(20) PRIMARY KEY,
    label        VARCHAR(30) NOT NULL,        -- e.g. Cashpower
    company      VARCHAR(40) NOT NULL,        -- e.g. Solomon Power
    icon         VARCHAR(20) NOT NULL,        -- a keyword; the web page picks the picture
    note         VARCHAR(40) NOT NULL,
    field_label  VARCHAR(30) NOT NULL,        -- "Meter number" / "Account number"
    field_hint   VARCHAR(30) NOT NULL,
    issues_token BOOLEAN     NOT NULL DEFAULT FALSE,
    active       BOOLEAN     NOT NULL DEFAULT TRUE,
    sort_order   INTEGER     NOT NULL DEFAULT 0
);

-- The ledger. One row per money movement:
--   topup    : money in      (to_user set)
--   transfer : user to user  (from_user and to_user set)
--   bill     : user to biller (from_user and biller set)
CREATE TABLE transactions (
    id         BIGSERIAL PRIMARY KEY,
    ref        VARCHAR(20)   NOT NULL UNIQUE,
    type       VARCHAR(10)   NOT NULL CHECK (type IN ('topup', 'transfer', 'bill')),
    from_user  BIGINT        REFERENCES users(id),
    to_user    BIGINT        REFERENCES users(id),
    biller     VARCHAR(20)   REFERENCES billers(code),
    account_no VARCHAR(14),
    token      VARCHAR(30),
    amount     NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    note       VARCHAR(40),
    created_at TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT tx_shape CHECK (
           (type = 'topup'    AND to_user   IS NOT NULL AND from_user IS NULL     AND biller IS NULL)
        OR (type = 'transfer' AND from_user IS NOT NULL AND to_user   IS NOT NULL AND biller IS NULL
                              AND from_user <> to_user)
        OR (type = 'bill'     AND from_user IS NOT NULL AND to_user   IS NULL     AND biller IS NOT NULL
                              AND account_no IS NOT NULL)
    )
);

CREATE INDEX tx_from_idx ON transactions (from_user, id DESC);
CREATE INDEX tx_to_idx   ON transactions (to_user,   id DESC);

-- ---------------------------------------------------------------
-- Starting data
-- ---------------------------------------------------------------

INSERT INTO billers (code, label, company, icon, note, field_label, field_hint, issues_token, sort_order) VALUES
    ('cashpower', 'Cashpower', 'Solomon Power', 'bolt', 'Prepaid electricity', 'Meter number',   'e.g. 04123456789', TRUE,  1),
    ('cashwater', 'Cashwater', 'Solomon Water', 'drop', 'Water bill',          'Account number', 'e.g. 20481234',    FALSE, 2);

-- Demo accounts (PINs are stored as bcrypt hashes): demo/1234, maria/1111, juan/2222
INSERT INTO users (username, full_name, pin_hash) VALUES
    ('demo',  'Demo User',      '$2y$10$FFGyfQZY8O.WceRiRoR75efbsTaxiAwWa0lftpAn4gqeNxYEaq37W'),
    ('maria', 'Maria Santos',   '$2y$10$uMADpQ9mt70ZChRzSSYTKebrVnnpzvpR9D28WTkCgD/ShCKJinE42'),
    ('juan',  'Juan Dela Cruz', '$2y$10$tGn/KkBywJRxR82XYfs2KOWP/.HjBDyDFmW.xFLMQEU/3B.BItrx.');

INSERT INTO wallets (user_id, balance)
SELECT id, CASE username WHEN 'demo' THEN 1000 WHEN 'maria' THEN 500 ELSE 750 END
FROM users;

INSERT INTO transactions (ref, type, to_user, amount, note)
SELECT 'MSSEED' || upper(username), 'topup', id,
       CASE username WHEN 'demo' THEN 1000 WHEN 'maria' THEN 500 ELSE 750 END,
       'Starting balance'
FROM users;

-- ---------------------------------------------------------------
-- Least privilege: the web app can read and write data, nothing else.
-- It cannot create or drop tables, and it cannot delete transactions.
-- ---------------------------------------------------------------

GRANT SELECT, INSERT, UPDATE ON users, wallets TO mseleni_app;
GRANT SELECT, INSERT         ON transactions     TO mseleni_app;
GRANT SELECT                 ON billers          TO mseleni_app;
GRANT USAGE ON SEQUENCE users_id_seq, transactions_id_seq TO mseleni_app;

COMMIT;
