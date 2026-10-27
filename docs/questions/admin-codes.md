# Questions for Matt — Module A: Admin prefixes, suffixes, sequences

1. **Sort order of prefixes.** Active prefixes are shown by their "position in lists" number (colors 10–100, inherited 200+, legacy 300). Is that the order you want in the Assign dropdown too, or should the dropdown be alphabetical?
2. **Who may move a prefix counter?** Today it needs both "Manage prefixes" and "Set next numbers". If the same people always have both, we could drop the second check.
3. **Minimum digits on an existing prefix.** Changing it only changes how codes are *displayed* (`Y1716` vs `Y01716`); the stored number is untouched. Should changing it be locked once codes exist, like the letters are?
4. **Code logs link.** The prefix page links "see the log" to `/codes/logs?prefix=Y`. Confirm with the Code logs module that it reads a `prefix` query parameter.

## Round 2

- A prefix's link to a **switched-off** suffix is now kept automatically when the prefix is edited. To remove such a link you switch the suffix on, untick it, and switch it off again. Is that acceptable, or should the greyed box be untickable directly?
- Counters can be moved **down** as far as the number after the highest one used (never onto a used number). Is that what you want, or should they be strictly forward-only from the current setting?
