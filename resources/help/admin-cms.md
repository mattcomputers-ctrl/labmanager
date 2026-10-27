# Help: the Mar-Kov CMS connection

Chroma can read the customer list from the Mar-Kov CMS so the lab picks real customer names on projects. The link is **read-only**: Chroma never writes anything to the CMS. It needs the "Manage the CMS connection" permission.

## Before you start

Ask the CMS administrator for:

1. the SQL Server host (name or IP address) and port (normally 1433),
2. the database name (normally `CMS`),
3. a **read-only** username and password made for Chroma,
4. which **table** holds customers, and which **columns** hold the customer ID, the customer name and (if there is one) an "active" flag.

Chroma does not guess any of this.

## Setting it up

1. Open **Admin → CMS connection** and fill in the SQL Server details. The password is saved in the server's config file, never in the database; the page only ever says "set" or "not set". It cannot contain a double quote (") or a backslash (\) — ask the CMS administrator for a read-only password without them. The connection cannot be turned on until a password is saved.
2. Fill in the table and column names exactly as the CMS administrator gave them. The optional **Filter** is the part after `WHERE` if some rows should be left out.
3. Press **Save CMS settings**, then **Test connection**. Chroma shows the first 10 rows the query returns. If the ID and Name columns look like real customers, the mapping is right.
4. Tick **Connection on** and save again. From now on customers are synced every night; **Sync customers now** runs it immediately.

If the page says **SQL Server driver not installed**, the installer could not install the Microsoft driver. Re-run `install.sh` on the server or ask whoever set it up. Until then the connection cannot be used, but customers can still be added by hand.

## What a sync does

- A CMS customer is matched first on its CMS ID, then on its name (ignoring case and punctuation). A name match remembers the CMS ID for next time.
- Customers Chroma does not have are added, marked as coming from the CMS.
- Names of customers that came from the CMS follow the CMS. Customers typed in by hand keep their name.
- A customer that disappears from the CMS is left alone unless **Mark customers that disappear from the CMS as inactive** is ticked. Nothing is ever deleted.
- The result of every sync is listed under **Recent syncs**, and the last result is shown at the top of the page.

Everything in Chroma works with the connection turned off.
