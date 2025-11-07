# MakeHaven Stripe module

This module provides helper utilities for MakeHaven staff to jump from a Drupal
user account to the corresponding Stripe customer record, creating the customer
in Stripe if one does not already exist.

## Current capabilities
- `/admin/stripe/open-customer/{uid}` (requires the `administer users`
  permission) redirects to the Stripe dashboard for the matched customer.
- If the target Drupal user lacks a stored Stripe customer ID but has an email
  address, the module searches Stripe for a matching customer or creates one and
  stores the customer ID back on the Drupal user record.
- Helper methods exist for portal session creation and subscription creation for
  future integrations.

## Configuration steps
1. **Install dependencies** – From the Drupal project root run `composer install`
   so the `stripe/stripe-php` requirement in this module's `composer.json` is
   satisfied.
2. **Create a storage field** – Add a plain text field to the User entity to
   hold the Stripe customer ID. Any machine name works; pick whatever matches
   your naming conventions. Grant the necessary view/edit permissions for staff
   as appropriate.
3. **Open the settings form** – Visit `admin/config/services/mh-stripe` (under
   *Configuration → Web services*). From there you can:
   - Select the user field that should store the Stripe customer ID.
   - Paste a Stripe Billing Portal configuration ID (`pc_...`) if you want it
     applied automatically when generating portal sessions. Create the ID from
     the Stripe Dashboard → Settings → Billing → Customer portal, add a new
     configuration with the limited actions you want members to take (e.g.
     view invoices only), then copy the generated `pc_...` value into the form.
   - Store a Stripe secret key directly in Drupal config if desired.
4. **Secret management guidance** – For production consider keeping secrets in
   `settings.php` or a Key module entry instead of Drupal config. If you leave
   the secret blank in the settings form, the module will fall back to the
   `$settings['stripe.secret']` value. You can also clear a stored secret via
   the form to return to file-based configuration.
5. **Permissions** – The shortcut route piggybacks on the `administer users`
   permission. Only grant that to trusted staff since it exposes Stripe access.
6. **Backfill stored customer IDs** – After configuring the module use the
   *Fetch existing Stripe customers* button on the settings form or run `drush
   mh-stripe:fetch-existing-customers` to populate the Stripe customer field for
   any user whose email already matches a customer in Stripe. Use
   `drush mh-stripe:backfill-customers` only if you also want to create Stripe
   customers for users that do not already have one.

## Using the customer shortcut
1. Visit a user's Drupal profile (`/user/{uid}`) so you have the UID handy.
2. Navigate to `/admin/stripe/open-customer/{uid}` (replace `{uid}` with the
   numeric user ID). If the user already has a Stripe customer ID stored, you
   will be redirected straight to the Stripe dashboard. Otherwise, the module
   will try to find or create a customer based on the user's email and then
   redirect you.
3. If the user has no email address, or if the configured Stripe customer field
   is missing from the site, the module will show an error message instead of
   redirecting.

## Warnings and future enhancements
- Secrets stored in Drupal config sync across environments. Prefer a
  file-based include or the Key module for production secrets, and wipe the
  stored value after use if you paste one into the settings form.
- Audit logs are not written. Track Stripe activity separately if you need a
  record of staff actions.
- The helper methods for portal sessions and subscriptions are stubs intended
  for future integration work; they currently mirror Stripe's defaults with
  minimal validation.
