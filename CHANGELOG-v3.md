# Changelog - Version 3

## Breaking Changes

### 🚫 Nova 4 Support Dropped
- **Removed:** Support for Laravel Nova 4
- **Impact:** If you're using Nova 4, you'll need to upgrade to a supported Nova version or remain on v2 of this package

### 🔧 PHP Version Requirement
- **Changed:** Minimum PHP version is now 8.3
- **Impact:** Projects running PHP < 8.3 must upgrade their PHP version before updating to v3

### ⚡ Livewire Classes Removed
- **Removed:** All Livewire classes and components
- **Impact:** 
  - Published Livewire config files must be manually removed from your application
  - Any custom Livewire implementations depending on this package's Livewire classes will need to be refactored
  - Check your `config/` directory for any published Livewire-related configuration files and remove them

## Migration Guide

### Before Upgrading
1. Ensure your project is running PHP 8.3 or higher
2. Remove any published Livewire configuration files related to this package
3. Review your codebase for any dependencies on the removed Livewire classes

### After Upgrading
1. Clear your application cache: `php artisan cache:clear`
2. Clear your configuration cache: `php artisan config:clear`
3. Test your application thoroughly to ensure no functionality is broken