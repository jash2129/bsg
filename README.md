# BSG Garments: shop code

Code for https://bsggarments.com (WordPress + WooCommerce on Hostinger).

| Folder | What it is |
|---|---|
| `wp-content/themes/bsg-minimal-child` | The shop theme (child of GeneratePress) |
| `wp-content/plugins/bsg-india-post` | India Post booking, labels and tracking |

**Only code lives here.** Products, orders, customers and product photos live on the live site and are never deployed from here.

## How deploys work

Every push to `main` runs `.github/workflows/deploy.yml`, which copies these two folders to the live site over SSH (rsync) and clears the cache.
It never touches the database, `wp-content/uploads`, `wp-config.php` or other plugins.

## One-time setup

1. On your computer create a deploy key (no passphrase):
   `ssh-keygen -t ed25519 -f bsg_deploy -C "github-deploy"`
2. Hostinger hPanel > Advanced > SSH Access: add the **public** key (`bsg_deploy.pub`).
3. GitHub repo > Settings > Secrets and variables > Actions > New repository secret. Add:
   - `SSH_KEY`: the whole **private** key file (`bsg_deploy`)
   - `SSH_HOST`: `82.112.228.14`
   - `SSH_PORT`: `65002`
   - `SSH_USER`: `u967763077`
   - `REMOTE_ROOT`: `/home/u967763077/domains/bsggarments.com/public_html`
4. Delete the private key file from your computer once it is saved in GitHub.
5. Push to `main`, or run the workflow by hand from the Actions tab.

## Rules

- Never commit passwords, API keys, `wp-config.php`, database dumps or uploads.
- India Post and Razorpay keys are entered in WordPress admin (or `wp-config.php` on the server), never here.
- Keep this repository **private**.
