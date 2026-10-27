MSCare Dental Clinic - combined site (patient login now lives in the public site)
================================================================================

Folders (copy both into C:\xampp\htdocs\)
  mscare_users\   public website + patient login + patient portal (new combined design)
  mscareadmin\    clinic admin site (unchanged)

Open
  Patient site : http://localhost/mscare_users/        (Log in button is in the top bar)
  Admin site   : http://localhost/mscareadmin/login.php
  DB check     : http://localhost/mscare_users/check-db.php

Accounts
  Patients register at register.php. Admin accounts live in the admins table (see database\seed.sql).

Database (one database for everything)
  PostgreSQL  host localhost | port 5432 | db mscare | user postgres | password Veron
  Settings: mscare_users\includes\db.php and mscareadmin\includes\db.php
  Fresh:    createdb -U postgres mscare
            psql -U postgres -d mscare -f mscare_users\database\mscare_full.sql
  Existing: psql -U postgres -d mscare -f mscare_users\database\seed.sql
  (the old mscare_users schema.sql was removed - mscare_full.sql is a superset of it)
  Enable extension=pdo_pgsql and extension=pgsql in php.ini.

What changed
  - Patient pages moved into mscare_users: login, register, dashboard, book, appointments,
    treatment history, dental record, profile - all in the public-site design.
  - Header shows "Log in" (or the patient's name + Log out once signed in).
  - Guests can still send a booking request; signed-in patients are sent to the portal booking.
  - Booking in the portal reuses the same step-by-step picker as the public site.
  - Bug fixed: allergies / conditions checkboxes only saved the last ticked item.
  - Bug fixed: dental chart colours never showed on Treatment History.

Admin Settings (mscareadmin\settings.php)
  - Menu with 6 sections: Clinic Profile, Appointment Preferences, Notifications,
    Account & Security, Services & Pricing, Privacy & System.
  - Creates its own tables on first visit (clinic_settings, admin_audit_logs) - no manual SQL needed.

Notifications
  - Admin bell (every admin page): pending booking requests + appointments cancelled by a patient (last 3 days).
  - Appointments page: bell icon on a row sends a reminder to that patient.
  - Confirm / cancel / reschedule in the admin site also notify the patient (Settings > Notifications > "Patient confirmation emails" turns this off).
  - Patient bell (top bar, signed in): shows those messages, unread count, "Mark all as read".
  - No new tables needed: it uses the existing "notifications" table.

Dialogs
  - Confirm / prompt / error boxes are now in-page dialogs (mscareadmin\dialog.js, mscare_users\assets\dialog.js),
    not the browser's "localhost says" pop-ups.
