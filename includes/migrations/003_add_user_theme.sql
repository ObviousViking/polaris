-- Per-user light/dark theme preference, set from user_profile.php and read
-- by header.php / includes/embedded_header.php to stamp data-theme on <html>.
-- Defaults to 'dark' to match every existing user's current (only) experience.

ALTER TABLE users
  ADD COLUMN theme enum('dark','light') NOT NULL DEFAULT 'dark';
