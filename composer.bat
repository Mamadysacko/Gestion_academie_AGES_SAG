@echo off
rem Raccourci : lance Composer (composer.phar) avec le PHP de XAMPP.
rem   composer.bat install    = installe ou reinstalle vendor\ (PHPUnit)
"C:\xampp\php\php.exe" "%~dp0composer.phar" %*
