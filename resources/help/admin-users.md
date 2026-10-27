# Help: users and groups

Every person who uses Chroma has their own account, so the logs always show who assigned a code or changed a project. Accounts are managed under **Admin → Users**. You need the "Manage users and groups" permission to open it.

## Adding a user

1. Go to **Admin → Users** and press **Add a user**. In Easy mode Chroma walks you through it one question at a time; in Expert mode everything is on one page.
2. Choose a **username** (what they type to sign in; lowercase, no spaces, for example `jsmith`) and their **full name** (what everyone else sees).
3. Decide on the first password. The easiest choice is **Make one up for me**: Chroma picks a memorable temporary password and shows it once at the end, with a Copy button. You can also type one yourself (at least 10 characters).
4. Tick the **groups** they belong in. Lab technicians go in **Lab**, sales staff in **Sales**, people who only need to look go in **Read only**.
5. Press **Create the account** and hand them the temporary password.

Whichever way the password was set, the person must choose their own password the first time they sign in.

## Resetting a password

Open the user from the list and press **Reset password**. Chroma sets a temporary password (made up or typed by you), signs them out of any other devices, and asks them to choose a new one at their next sign-in. The temporary password is shown once; if you lose it, just reset again.

## Deactivating someone

When a person leaves, open their account and press **Deactivate account**. They are signed out and can no longer sign in. Nothing they did is removed: their name stays on every project and code. If they come back, press **Reactivate**.

A few safety rules apply:

- You cannot deactivate your own account.
- Only an Administrator can deactivate another Administrator, or remove someone from the Administrators group.
- You cannot take yourself out of the Administrators group (or deactivate your own account): you would lose every admin screen the moment you pressed Save. Ask another Administrator to do it.
- The last active Administrator can never be deactivated or taken out of the group, so the lab can never lock itself out. Make a second person an Administrator before changing the first.

## Groups

A **group** is a set of people who may do the same things. Under **Admin → Users → Groups** you can see every group, how many people are in it, and what it allows. Open a group to:

- change its name and description,
- tick or untick what its members may do (grouped by area: Projects, Codes, Reports, Admin),
- add or remove members.

Someone in several groups gets everything **each** of their groups allows. The built-in groups (Administrators, Lab, Sales, Read only, R&D) can be edited but not deleted. Administrators always hold every permission.

If you delete a group that has members, Chroma asks you to confirm and explains that those people keep only what their other groups allow.

## What the permissions mean

Each tick box on the group page has a one-line explanation under it. The most common ones:

- **Look up codes** — use code lookup and the code logs. Sales will use this constantly.
- **Assign E numbers / Assign sell numbers** — reserve the next code. Only give this to people who really hand out codes.
- **Void codes** and **Enter a specific code by hand** — rare, every use is logged; usually only the lab lead.
- **Manage users and groups** — what you are reading about. Keep it to a few people.
