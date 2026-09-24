# First-run Installer Architecture

Entry point: `/install` only when no install lock exists.

Required sequence:
1. Welcome
2. Server Requirements
3. File/Folder Permissions
4. Database Configuration
5. Test Database Connection
6. Platform Information
7. Create Super Admin
8. Storage Configuration
9. Cache/Queue Configuration
10. Mail Configuration
11. Run Migrations
12. Seed Required Core Data
13. Generate Application Keys
14. Health Check
15. Finish

Bootstrap includes `InstallState`, `InstallerChecklist`, route and initial view. Step processors and secure persistence are M7 issue work.

After successful finish:
- write `storage/app/system/installed.lock`;
- block installer access;
- persist installed version;
- redirect to admin login.

Secrets are never logged or committed.
