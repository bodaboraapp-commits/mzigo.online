@echo off
REM Start the dedicated Apache preview for the Mzigo.online clone on port 8090.
REM Keeps this window open; close it (or Ctrl+C) to stop the preview.
REM The shared XAMPP Apache on port 80 is left untouched.
"C:\xampp\apache\bin\httpd.exe" -f "C:\xampp\htdocs\www\mzigo\_server\httpd.conf" -D FOREGROUND
