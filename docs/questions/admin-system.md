# Questions for Matt — module E2

1. **CMS customer table and columns.** The CMS page has fields for the table, ID column, name column, optional active-flag column and an optional filter, and says "ask the CMS administrator" rather than guessing. What are they? (Spec §12 question 6.)
2. **Should customers that disappear from the CMS be marked inactive?** Default is no (they stay active). There is a tick box on the CMS page if you want yes.
3. **DHCP from the Network page.** Should changing the server to automatic (DHCP) addressing be allowed from Chroma at all? It is offered now; if a server should always be static we can hide the option.
4. **Backup download size.** Backups are streamed through PHP; if the database plus attachments grows past a few hundred MB, downloads from the browser may be slow and we should serve them through nginx instead. Fine for v1?
5. **API token scope.** Tokens are read-only and all-or-nothing today. Do you want per-system tokens limited to codes only vs projects too?
