# Email Notification Troubleshooting & Fixes

## ✅ Issues Fixed

### 1. Petty Cash Limit Incorrect
**Status**: FIXED ✅  
**Old Value**: 50,000 JMD  
**New Value**: 5,000 JMD  
**Files Updated**:
- `database_fixes.sql` - Updated INSERT statement
- `migrations/011_fix_petty_cash_and_email_settings.sql` - New migration

**Apply Fix**:
```sql
UPDATE `system_config` 
SET `config_value` = '5000'
WHERE `config_key` = 'petty_cash_limit';
```

### 2. Direct Procurement Threshold Incorrect
**Old Value**: 3,000,000 JMD  
**New Value**: 500,000 JMD  

```sql
UPDATE `system_config` 
SET `config_value` = '500000'
WHERE `config_key` = 'direct_procurement_threshold';
```

### 3. Email Notifications Not Sending

#### Root Causes (Check in This Order):

**A. Enable notifications in database**
```sql
INSERT INTO `system_config` (`config_key`, `config_value`, `description`)
VALUES ('enable_notifications', '1', 'Enable/disable email notifications (1=enabled, 0=disabled)')
ON DUPLICATE KEY UPDATE `config_value` = '1';
```

**B. SMTP credentials not configured in `config/app.php`**
Check these are defined:
```php
define('MAIL_HOST', 'smtp.gmail.com');      // SMTP server
define('MAIL_PORT', 587);                   // SMTP port
define('MAIL_USER', 'your-email@gmail.com'); // Your email
define('MAIL_PASS', 'app-password');        // App password (NOT regular password)
define('MAIL_FROM', 'noreply@example.com');
define('MAIL_FROM_NAME', 'Government Chemist - PRMS');
```

**C. No approver users with required roles**
```sql
-- Check for approvers
SELECT u.user_id, u.first_name, u.email, r.name
FROM users u
INNER JOIN roles r ON u.role_id = r.id
WHERE r.name IN ('HOD', 'Director HRM&A', 'Deputy Government Chemist')
AND u.is_active = 1;
```

**D. Database query errors**
Add the `first_name` column to users table if missing:
```sql
ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(50) AFTER `full_name`;
ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(50) AFTER `first_name`;

-- OR split full_name if needed:
UPDATE `users` 
SET `first_name` = SUBSTRING_INDEX(`full_name`, ' ', 1),
    `last_name` = SUBSTRING_INDEX(`full_name`, ' ', -1);
```

## 📊 Diagnostic Tool

A comprehensive diagnostic tool has been created to test the email system:

**Access**: `https://yoursite.com/tools/email_diagnostic.php`

### What It Checks:
1. ✓ SMTP Configuration
2. ✓ Database Settings
3. ✓ Approver Users Exist
4. ✓ Petty Cash Limit Value
5. ✓ Send test emails
6. ✓ Review error logs

### How to Use:
1. Open `/tools/email_diagnostic.php` in browser
2. Review all configuration checks
3. Use "Test Email Send" to verify SMTP works
4. Use "Test Notification" to test with real requests

## 🔍 Enhanced Logging

Detailed logging has been added to trace email sending:

**Check logs for**:
```
NOTIFY: Starting approval notification for request XXX to approver YYY
NOTIFY: Found approver email: user@email.com
MAIL: Attempting to send email to user@email.com
MAIL: Email sent successfully to user@email.com
```

**View logs**:
```bash
# Recent notifications
tail -100 /path/to/php-error.log | grep "NOTIFY\|MAIL"

# All notification errors
grep -i "approval needed error\|mailer error" /path/to/php-error.log

# Watch in real-time
tail -f /path/to/php-error.log | grep -i "NOTIFY\|MAIL"
```

## Email Flow Verification

### Step 1: Request Submitted
```
/procurement/submit.php
  ├─ Calls getApprovalChain() → returns first approver role
  ├─ Finds user with that role
  └─ Calls notifyApprovalNeeded(request_id, stage, user_id)
```

### Step 2: Notification Function
```
notifyApprovalNeeded()
  ├─ Gets request details from DB
  ├─ Gets approver email via getUserEmail()
  ├─ Builds HTML email
  └─ Calls sendMail($email, $subject, $html)
```

### Step 3: Mail Sending
```
sendMail()
  ├─ Creates PHPMailer instance
  ├─ Configures SMTP (Host, Port, User, Pass)
  ├─ Connects to SMTP server
  └─ Sends email or logs error
```

## Gmail SMTP Setup (Most Common)

If using Gmail:

1. **Enable 2-Factor Authentication** on your Google account
2. **Get App Password**:
   - Go to: https://myaccount.google.com/apppasswords
   - Select "Mail" and "Windows Computer" (or your OS)
   - Google generates a 16-character password
3. **Update config/app.php**:
   ```php
   define('MAIL_HOST', 'smtp.gmail.com');
   define('MAIL_PORT', 587);
   define('MAIL_USER', 'your-email@gmail.com');
   define('MAIL_PASS', 'xxxx xxxx xxxx xxxx');  // 16-char app password
   define('MAIL_FROM', 'your-email@gmail.com');
   define('MAIL_FROM_NAME', 'Government Chemist - PRMS');
   ```

4. **Test Connection**:
   - Use diagnostic tool
   - Send test email to verify

## Common Issues & Solutions

### "SMTP connect() failed"
- ✓ Check MAIL_HOST and MAIL_PORT are correct
- ✓ Verify port 587 is not blocked by firewall
- ✓ Try port 465 instead (ENCRYPTION_SMTPS)

### "Authentication failed"
- ✓ Check MAIL_USER and MAIL_PASS are correct
- ✓ For Gmail, ensure using App Password (not regular password)
- ✓ Check user account is active and not locked

### "SMTP Error: Could not authenticate. SMTP server error: QUIT command failed"
This is a single failure, not two: the login was rejected and the `QUIT`
notice is just the aborted connection being torn down. `sendMail()` now
appends a `How to fix:` hint to this log line.

- ✓ Re-check `MAIL_USER` / `MAIL_PASS` in `.env`
- ✓ For Gmail/Workspace, `MAIL_PASS` must be a 16-character **App Password**
  generated on an account with 2-Step Verification enabled — the normal
  account password is always rejected
- ✓ Spaces in a Gmail App Password (`abcd efgh ijkl mnop`) are display-only
  and are stripped automatically; surrounding whitespace in `.env` values is
  also trimmed
- ✓ If the value contains `#` or spaces, quote it: `MAIL_PASS="..."`
- ✓ Confirm `MAIL_USER` is the full mailbox address, not just the local part

### "Email appears to send but not received"
- ✓ Check email logs: `tail -f /path/to/php-error.log`
- ✓ Check spam/junk folder
- ✓ Verify email address exists in users table
- ✓ Try sending test email from diagnostic tool

### No logging output
- ✓ Check error_log location in php.ini
- ✓ Ensure PHP error logging is enabled
- ✓ Check file permissions on log directory

## Files Modified

✅ **config/notifications.php**
- Added logging throughout email functions
- Fixed approver detection

✅ **config/mailer.php**
- Enhanced error logging
- SMTP configuration logging

✅ **database_fixes.sql**
- Updated petty_cash_limit: 50000 → 5000
- Updated direct_procurement_threshold: 3000000 → 500000
- Added enable_notifications flag

✅ **New Files**
- `tools/email_diagnostic.php` - Comprehensive diagnostic tool
- `migrations/011_fix_petty_cash_and_email_settings.sql` - SQL fixes

## Next Steps

1. **Apply Database Fixes**:
   ```bash
   # Run migration
   mysql -u user -p database < migrations/011_fix_petty_cash_and_email_settings.sql
   
   # OR run individual SQL queries from diagnostic tool
   ```

2. **Verify SMTP Configuration**:
   - Check `config/app.php` has valid SMTP credentials
   - Test with diagnostic tool

3. **Check Error Logs**:
   - Look for "NOTIFY" and "MAIL" messages
   - Identify specific SMTP errors

4. **Run Diagnostic Tool**:
   - Access `tools/email_diagnostic.php`
   - Test email sending
   - Review configuration

5. **Submit Test Request**:
   - Create a procurement request
   - Check if email is sent
   - Monitor logs in real-time

## Support Commands

```bash
# Quick email status check
grep -c "Email sent successfully" /path/to/php-error.log

# Count failures
grep -c "Mailer error" /path/to/php-error.log

# List all SMTP errors
grep "SMTP" /path/to/php-error.log | tail -20

# Count notifications sent today
grep "Email sent successfully" /path/to/php-error.log | wc -l
```
