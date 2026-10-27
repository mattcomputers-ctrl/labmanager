# Module D questions for Matt

1. **SDS link for formula versions.** `E6374-10` currently links to the SDS for the base number (`…/E6374`) because the SDS system is keyed by E#. If versions have their own sheets, say so and the link will use the full code. *Default: base number.*
2. **SDS URL shape.** The link is base URL + `/E9102`. If the SDS system wants a query string (`…?code=E9102`), put `{code}` in the base URL under Admin → System and it is substituted. Confirm which shape the SDS system uses.
3. **Who may edit notes and descriptions on code pages?** Currently anyone with "Assign E numbers" or "Assign sell numbers". Sales (lookup only) cannot. Should Sales be able to add notes?
4. **Reason required for aliases.** Adding another sell number to an existing product requires a reason (relabel / customer-specific / new version / resale / other). For brand-new products it is optional. Is that the right balance, or should every sell number carry a reason?
5. **Prefix log order.** Logs show the highest number first (newest assigned number), which matches "newest first" for sequential counters. If you prefer date-assigned order (relevant after the legacy import, where dates and numbers may disagree), say so — it is a one-line change and the column is indexed.
6. **Export of the whole cross-reference.** Lookup export is capped at 20,000 rows per download; a full-table export is available per prefix from the logs. Is a single "everything" export needed?
