# ISOBuilder

A lightweight web interface to configure and build [SEAPATH](https://github.com/seapath/build_debian_iso) Debian ISO and QCOW2 images using [FAI](https://fai-project.org/).

ISOBuilder wraps the upstream `build_iso.sh` / `build_qcow2.sh` workflow with a browser-based dashboard: pick a `build_debian_iso` branch or tag, configure host settings, choose package classes and GRUB boot menu entries (or QCOW2 disk options), customize FAI files, and launch builds with live log streaming.

## Screenshots

### Dashboard

Configure hostname, credentials, SSH keys, the upstream `build_debian_iso` version, package classes, and boot menu entries from a single page. Choose ISO or QCOW2, then track builds and download the resulting image.

![Dashboard](docs/screenshots/dashboard.png)

### usercustomization editor

Browse and edit FAI customization files. The upstream `srv_fai_config/` tree is available read-only for reference.

![Editor](docs/screenshots/editor.png)

### Build logs

Follow build output in real time. Download the ISO or QCOW2 once the build completes.

![Build logs](docs/screenshots/build-logs.png)

## Features

- **Web dashboard** - configure hostname, passwords, SSH keys, and remote network settings from a single form
- **Upstream version selector** - pick a branch or tag of `build_debian_iso`; switching reclones the repository while keeping `usercustomization/`
- **ISO and QCOW2 targets** - build an installer ISO (`build_iso.sh`) or a VM disk image (`build_qcow2.sh`, with disk size and optional cloud-init). The QCOW2 option is shown only when the selected version provides `build_qcow2.sh`
- **Dynamic build options** - package classes and boot menu flags are read from the cloned `build_iso.sh` script, so the UI stays aligned with the selected upstream version
- **Build queue** - one build runs at a time; additional requests are queued automatically
- **Live logs** - follow build output in real time and download the resulting ISO or QCOW2 when ready
- **usercustomization editor** - browse and edit FAI customization files, with read-only access to upstream `srv_fai_config/`
- **Import / export** - backup and restore `usercustomization/` as a ZIP archive (including file permissions)
- **Password hashing** - generate sha512crypt hashes for `USERPW` / `ROOTPW` directly from the UI
- **Internationalization** - English and French UI

## Architecture

```
Browser
   |
   v
PHP web app (ISOBuilder)
   |
   +-- /tmp/isobuilder_workspaces/<user>/
   |       +-- .seapath_ref                                 <- selected branch or tag
   |       +-- build_debian_iso/                            <- git clone (per user)
   |               +-- usercustomization/                   <- editable FAI config
   |
   +-- /tmp/isobuilder_builds/<build_id>/                  <- logs, status, output.iso / output.qcow2
           +-- run_build.sh -> ./build_iso.sh --classes ... --menu ...
                            or ./build_qcow2.sh --vmdisksize ... [--cloud-init]
```

On login, each user gets a fresh clone of the upstream repository (default branch `main`, or the last version they selected). Build options are saved in `usercustomization/build_options.json` alongside `class/USERCUSTOMIZATION.var`.

## Requirements

### Web server

- PHP 8.0+ with extensions: `session`, `json`, `zip`
- A web server (Apache, nginx, or PHP built-in server for development)
- `git`, `htpasswd` (Apache utils), and shell access for the PHP process

### Build host

ISOBuilder delegates the actual image creation to the upstream [build_debian_iso](https://github.com/seapath/build_debian_iso) project, which requires:

- **Podman** (with `podman-compose`)
- **sudo** access for the user running builds
- Sufficient disk space for container images and ISO / QCOW2 output

The build host must be the same machine (or share the same filesystem) as the web server, since builds run locally via `nohup`.

## Installation

1. **Deploy the application**

   ```bash
   git clone <this-repo-url> /opt/isobuilder
   chown -R www-data:www-data /opt/isobuilder
   ```

2. **Configure the web server**

   Point your virtual host document root to `/opt/isobuilder` (or symlink it). Ensure PHP can execute shell commands (`git`, `podman`, etc.).

3. **Create authentication credentials**

   ISOBuilder reads users from `/opt/isobuilder/authlist.txt` in htpasswd format:

   ```bash
   htpasswd -B -c /opt/isobuilder/authlist.txt myuser
   chmod 640 /opt/isobuilder/authlist.txt
   chown root:www-data /opt/isobuilder/authlist.txt
   ```

   Supported hash formats: bcrypt (`$2y$`), Apache MD5 (`$apr1$`), SHA-1 (`{SHA}`).

4. **Adjust upstream settings (optional)**

   The dashboard lets each user pick a `build_debian_iso` branch or tag. Edit `config.php` only to change the git URL or the default version used before any selection:

   ```php
   define('SEAPATH_REPO_URL', 'https://github.com/seapath/build_debian_iso.git');
   define('SEAPATH_REPO_BRANCH', 'main');
   ```

5. **Ensure writable temp directories**

   The application stores workspaces and build artifacts under the system temp directory:

   - `/tmp/isobuilder_workspaces/`
   - `/tmp/isobuilder_builds/`

   The PHP process user must be able to create and write to these paths.

## Usage

### 1. Log in

Open the application in your browser and authenticate with your htpasswd credentials. On each login, the upstream repository is re-cloned at the selected (or default) version.

### 2. Choose the upstream version

In **Build Options**, select a `build_debian_iso` branch or tag, then click **Switch**. This reclones the repository; your `usercustomization/` files are kept. Package classes and boot menu flags are re-read from the new `build_iso.sh`. Use **Refresh list** to fetch the latest branches and tags from the remote. Applying the already selected version reclones it to pick up new commits (useful on `main`).

### 3. Configure the host

Fill in the dashboard form:

| Field | Description |
|-------|-------------|
| `HOSTNAME` | Target machine hostname |
| `USERPW` | User password hash (sha512crypt). Use the **Hash password** button to generate one |
| `myrootkey` / `myuserkey` / `ansiblekey` | SSH public keys injected during installation |
| `REMOTENIC` / `REMOTEADDR` / `REMOTEGW` / `REMOTEVLANID` | Optional out-of-band network configuration |

### 4. Select build options

- **Target** - **ISO** (installer image) or **QCOW2** (VM disk image), when the selected version includes `build_qcow2.sh`.
- **Package classes** (ISO) - FAI classes included in the image (e.g. `SEAPATH_CLUSTER`, `SEAPATH_DBG`). Options are parsed from `build_iso.sh`.
- **Boot menu items** (ISO) - GRUB entry flag combinations (e.g. `french,cluster`). You can define multiple lists; the first list becomes the default boot entry.
- **Disk size / cloud-init** (QCOW2) - size passed to `--vmdisksize` (e.g. `10G`) and optional `SEAPATH_CLOUD_INIT`.

Click **Save configuration** to persist without building, or **Save and start build** to launch immediately.

### 5. Monitor and download

Build status appears on the dashboard, including the upstream version used. Click **Logs** to stream output in real time. When the build completes successfully, download the ISO or QCOW2 from the dashboard or the logs page.

### 6. Advanced customization

Use the **Editor** to modify any file under `usercustomization/` - disk layouts, hooks, package lists, and more. The upstream `srv_fai_config/` tree is available read-only for reference.

Export your customization as a ZIP for backup or import it on another instance.

## Build queue

Only one build runs at a time (enforced by a mutex file). If a build is already in progress, new requests are placed in a FIFO queue and started automatically when the current build finishes.

On logout, all pending and running builds owned by the user are cancelled and their workspace is cleaned up.

## Project structure

```
.
├── index.php                  Login page
├── dashboard.php              Main configuration UI
├── build.php                  Save config and trigger builds
├── editor.php / editor_api.php  File tree editor
├── auth.php                   htpasswd authentication
├── config.php                 Paths, repo settings, build helpers
├── hash_password.php          sha512crypt password hashing API
├── switch_repo.php            Switch / refresh build_debian_iso branch or tag
├── export_usercustomization.php
├── import_usercustomization.php
├── stream_logs.php            Server-sent log streaming
├── start_next_build.php       Queue worker (CLI)
└── i18n/                      English and French translations
```

## Security notes

- Authentication relies on `/opt/isobuilder/authlist.txt`; protect this file accordingly.
- Builds run shell commands (`git`, `podman`, `build_iso.sh`, `build_qcow2.sh`) as the web server user. Restrict server access to trusted operators only.
- User workspaces and build artifacts live in `/tmp/` and are removed on logout.

## License

See the upstream [build_debian_iso](https://github.com/seapath/build_debian_iso) project for SEAPATH licensing terms.
