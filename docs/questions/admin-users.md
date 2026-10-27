# Questions for Matt — module E1 (Admin: users & groups, lists, customers)

1. **Should people with "Manage users and groups" who are not Administrators be able to make someone an Administrator?**
   I said no (only an Administrator can add to, or remove from, the Administrators group). If you want office staff to onboard
   new technicians without being administrators themselves, this still works: they just cannot hand out the Administrators group.
2. **Temporary password style.** Chroma makes up passwords like `Amber-Harbor-Pilot-42`. Is a three-word style fine for reading
   over the phone, or would you rather have a plain random string?
3. **Customer merge and the CMS id.** When a duplicate was created by the CMS sync and the one you keep was typed by hand, the
   merge moves the CMS id to the kept customer so the sync does not re-create the duplicate. Is that the behaviour you expect, or
   should the CMS-sourced row always be the one kept?
4. **Who may edit the Administrators group's description or members?** Anyone with "Manage users and groups" can change its
   description; only Administrators can change its membership. Confirm that split is right.
5. **Lists: should "Delete" exist at all?** It is only offered for entries nothing uses yet (e.g. a typo you just added). If you
   would rather have deactivate only, it is one line to remove.
6. **Do you want a separate "Customers" tab in the main navigation for sales** (read-only list with search), or is the admin
   screen plus the typeahead on projects enough?

7. **Should an Administrator be able to take themselves out of the Administrators group?** I now refuse it (decision 16): the
   reviewer showed that an admin who removes themselves is locked out of every admin page on the very next click. If Matt would
   rather allow it with a warning, it is a one-line change in `UserAdmin::assertMayChangeAdminMembership` and the note on the
   group page.
