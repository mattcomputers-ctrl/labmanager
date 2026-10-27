# Questions for Matt — Projects module (C)

1. **R&D numbering**: the module uses one shared project-number sequence for all types (so an R&D project is "Project 104" with a purple R&D badge). If you would rather see `RD-0001`, say so; it is a display change plus a second sequence.
2. **Who may see confidential R&D projects?** Currently: the lead, the team, whoever created it, the "R&D" group, administrators, and anyone given the "See confidential R&D" permission. Should Sales never see R&D projects at all, even non-confidential ones?
3. **Is the sell number always the iQC standard name?** The row shows an "iQC standard" hint under the sell number while the setting `sell_number_is_iqc_standard` is on (it is on by default). Turn it off in settings if that is wrong.
4. **Item cap of 50**: the setting `max_items_per_project` defaults to 50. Is 50 right for the biggest jobs you see?
5. **Attachment size**: `max_upload_mb` defaults to 25 MB. Are iQC exports or press-trial photos ever bigger?
6. **Auto-complete**: a project completes itself when every item is matched/approved/rejected/not needed. Should "rejected" count as done, or should a rejected color keep the project open?
7. **Who completes the project when the tech is not here?** Right now "Complete or cancel projects" is its own permission (Lab and R&D groups have it). Should Sales be able to cancel a project the customer withdrew?
