# Questions for Matt: Reports and the API

1. **Proof dates.** Items do not record when a proof was made, so "Proofs generated" dates an item by its completed date, then its started date, then the project's submitted date. If you want proofs per calendar month to be exact, we can add a `proofs_date` on the item (or log each proof in the activity feed). Worth it?
2. **Who may export reports?** Right now the CSV/Excel buttons on reports need "Export lists" (`codes.export`) as well as "See reports". The seeded *Read only* group can see reports but not export them. Should "See reports" alone allow export?
3. **Turnaround on cancelled projects.** Cancelled projects are ignored. Do you want a separate count of cancellations per customer/tech on that report?
4. **API token scope.** Every token can read every endpoint. Is a per-token restriction needed (e.g. a token that can read codes but not projects), or is one level of access enough for the ERP and the SDS system?
5. **API over HTTPS.** Tokens travel in a header, so on the plant network HTTP is tolerable, but once HTTPS is set up the API should be HTTPS-only. OK to enforce that when HTTPS is switched on?
6. **Codes per month for the imported history.** Imported codes keep the date from the spreadsheet; some rows have no date at all and will never appear on the monthly grid. Fine, or should undated codes be shown as a separate "no date" column?
