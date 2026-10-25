@echo off
rem Raccourci : lance PHPUnit avec le PHP de XAMPP, sans que php soit dans le PATH de Windows.
rem   phpunit.bat                             = toute la suite
rem   phpunit.bat --testsuite Unitaires
rem   phpunit.bat --filter testCreationValide
"C:\xampp\php\php.exe" "%~dp0vendor\bin\phpunit" %*
