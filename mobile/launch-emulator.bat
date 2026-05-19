@echo off
setlocal

set ANDROID_HOME=C:\Android\Sdk
set JAVA_HOME=C:\Program Files\Microsoft\jdk-17.0.19.10-hotspot
set ADB=%ANDROID_HOME%\platform-tools\adb.exe
set EMULATOR=%ANDROID_HOME%\emulator\emulator.exe
set AVD_NAME=Pixel7_API35

:: Check if emulator is already running
"%ADB%" devices | findstr "emulator" >nul 2>&1
if %ERRORLEVEL%==0 (
    echo Emulator already running.
    goto :launch_apps
)

:: Start emulator in background
echo Starting emulator...
start "" "%EMULATOR%" -avd %AVD_NAME% -no-audio -gpu swiftshader_indirect

:: Wait for boot
echo Waiting for emulator to boot...
:wait_loop
"%ADB%" shell getprop sys.boot_completed 2>nul | findstr "1" >nul
if %ERRORLEVEL% neq 0 (
    timeout /t 3 /nobreak >nul
    goto :wait_loop
)

echo Emulator ready!

:launch_apps
timeout /t 2 /nobreak >nul
echo Launching customer app...
"%ADB%" shell am start -n "site.fortunetttech.customer/.ui.auth.LoginActivity"
timeout /t 1 /nobreak >nul
echo Launching admin app...
"%ADB%" shell am start -n "site.fortunetttech.admin/.ui.auth.LoginActivity"

echo Done! Both apps launched.
endlocal
