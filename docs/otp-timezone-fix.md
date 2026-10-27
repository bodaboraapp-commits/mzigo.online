# OTP “expired immediately” — timezone fix

**Status:** fixed 27 October 2026.

## Symptom

A customer receives:

```text
Mzigo.online: your sign-in code is 7683. It expires in 10 minutes.
```

but entering the code immediately returns:

```text
That code is not correct or has expired.
```

## Root cause

The application sets PHP to:

```php
date_default_timezone_set('Africa/Nairobi');
```

but cPanel MariaDB commonly runs with a UTC session timezone.

The OTP expiry was written with:

```sql
DATE_ADD(NOW(), INTERVAL 10 MINUTE)
```

So MariaDB stored an expiry based on UTC, while PHP compared it as Nairobi time (+03:00). A 10-minute OTP therefore appeared to have expired about 3 hours earlier.

## Fix

1. `api/_bootstrap.php` aligns the PDO session timezone:

```sql
SET time_zone = '+03:00'
```

2. OTP expiry timestamps are now generated from PHP’s clock, not the database clock:
   - Customer login OTP: 10 minutes
   - Claim verification OTP: 10 minutes
   - Runner delivery OTP: 30 minutes

3. The timezone alignment is non-fatal: if the DB user cannot set a session timezone, the app logs it instead of taking the whole site down.

## Verified locally

```text
db_now:       2026-10-27 22:24:39
otp_expires:  2026-10-27 22:34:39
mins_left:    10
login:        True
redirect:     /customer
```

After a successful login the OTP is cleared.

## Production

- Deploy the latest Git commit
- Request a **new** code; old codes cannot be repaired
- Existing sessions may appear valid for a few extra hours because historical timestamps were stored in the old timezone
