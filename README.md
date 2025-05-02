# Avalara Extension
 
## Introduction
 
The Avalara Extension is built on top of the official Avalara plugin for Shopware 6. It provides enhancements, bug fixes, and improved handling of tax-related features.
 
> **This extension is not standalone.** You must first install and activate the official [Avalara plugin for Shopware 6](https://store.shopware.com/en/mopt799424961809f/avalara-plugin.html).
 
This extension helps merchants customize and stabilize Avalara integration in their Shopware stores by refining address handling, improving API logic, enhancing logs, and offering better backend feedback for failed validations.
 
### Key Features
 
1. **Fixes**
   - Resolves specific bugs encountered with the base Avalara plugin.
2. **Improved Address Handling**
   - Enhances how billing and shipping addresses are parsed and submitted.
3. **Logging & Debugging**
   - Extends logging capabilities for better debugging during tax calculations.
4. **Optimized API Usage**
   - Refines API request logic to minimize failures and increase reliability.
5. **Backend Feedback**
   - Provides clearer backend error messages when tax validation fails.
 
---
 
## Get Started
 
### Installation & Activation
 
1. **Install the Avalara Base Plugin**
 
   - Follow the official documentation to install and configure the Avalara plugin:
     [Avalara for Shopware 6](https://projects.mediaopt.de/projects/mopt-ecompp/wiki/English#AvalaraPlugin-f%C3%BCr-Shopware-6).
 
2. **Download This Extension**
 
   #### Git
 
   - Open your terminal and run the following command in your Shopware custom plugins directory (usually located at custom/plugins/):
     ```bash
     git clone https://github.com/solution25com/avalara-extension-shopware-6-solution25.git
     ```
 
3. **Install the Plugin in Shopware 6**
 
   - Log in to your Shopware 6 Administration panel.
   - Navigate to Extensions > My Extensions.
   - Locate the Avalara Extension and click Install.
 
4. **Activate the Plugin**
 
   - After installation, toggle the button to activate the extension.
 
 
5. **Verify Installation**
 
   - Check the list of installed plugins to ensure "Avalara Extension" is active.
   - It should appear along with its version.
     
![avalara](https://github.com/user-attachments/assets/535e6511-b192-4929-a77d-995f8311d558)
 
---
 
## Plugin Configuration
 
- This extension **does not add new settings** to the admin panel.
- It works silently in the background by extending the behavior of the Avalara base plugin.
- Ensure the base plugin is **fully configured and functioning** before activating this extension.
 
---
 
## How It Works
 
1. **Avalara Base Handles Tax Calculation**
 
   - The core Avalara plugin sends address and order data to Avalara for tax estimation.
 
2. **Extension Enhances Communication**
 
   - The extension intercepts and improves how data is structured and sent to Avalara.
   - Additional error-handling logic helps prevent failed requests.
 
3. **Improved Logs & Feedback**
 
   - If a tax validation fails, the extension adds clearer error messages to Shopware’s backend.
   - Developers and store managers can easily locate and debug issues.
 
4. **Address Validation Enhancements**
 
   - The extension ensures that customer address data is formatted correctly.
   - In case of missing or inconsistent fields, it attempts a graceful fallback.
 
---
 
## Best Practices
 
- **Always keep the base Avalara plugin up to date** to benefit from ongoing support and updates.
- **Use staging environments** to test plugin changes before deploying to production.
- **Ensure customer data is clean**—valid postal codes and addresses improve tax accuracy.
- **Clear Shopware cache** after activating or updating the plugin.
 
---
 
## Troubleshooting
 
- **Plugin not working?**
  - Ensure the base Avalara plugin is installed and activated first.
 
- **Still seeing broken tax calculations?**
  - Check Shopware logs and Avalara logs for clues.
  - Confirm address data is complete and valid.
 
- **Errors in checkout or backend?**
  - Check for conflicts with other third-party plugins that modify checkout or address handling.
 
- **No plugin settings available?**
  - This plugin operates transparently with no admin settings required.
 
---
 
## FAQ
 
- **Can I use this plugin without the Avalara plugin?**  
  No. This extension depends on the official Avalara plugin.
 
- **Does this override Avalara functionality?**  
  It enhances specific areas like error handling, API robustness, and logging, but does not replace the Avalara core logic.
 
- **Can I disable the extension without disabling Avalara?**  
  Yes. The extension is modular and can be deactivated independently, although enhanced features will no longer apply.
 
