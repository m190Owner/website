# Building the Pwn Lab VM image (`alpine.img` + `state.bin`)

The lab boots a **32-bit** Alpine Linux in the browser via [v86](https://github.com/copy/v86)
and injects the crackme over 9pfs. This file is the runbook for (re)building the two VM
assets. They are **gitignored** (too big for git) and must be uploaded to the host
out-of-band into `lab/vm/`.

## Why 32-bit

v86 has **no 64-bit support** ("64-bit kernels are not supported" — copy/v86 README). So
the guest is 32-bit i386 and the crackmes must be 32-bit too, or they won't execute inside
the VM (`Exec format error`). The crackmes in `lab/binaries/` are built 32-bit for exactly
this reason. If you ever rebuild them:

```bash
cd crackmes
python gen_encoded.py
for t in easy medium hard; do
  zig cc -target x86-linux-musl -static -Os -s -fno-stack-protector \
         -fno-asynchronous-unwind-tables -no-pie $t.c -o crackme-$t
done
cp crackme-easy crackme-medium crackme-hard ../lab/binaries/
# file lab/binaries/crackme-*  -> must say "ELF 32-bit ... Intel i386"
# flag hashes are unchanged as long as flags.txt is unchanged (they match LAB_HASH_* in api.php)
```

## ⚠️ Use the STANDARD ISO, not virt (verified 2026-08-23)

Driving a test build revealed the original plan's ISO choice is **wrong**: the
`alpine-virt` kernel is minimal (virtio only) and has **no driver for v86's emulated NIC**
(an NE2000-compatible RTL8029). Inside a virt guest `/sys/class/net/` shows only `lo`, so
`setup-alpine` fails with `ioctl ... No such device` and `apk` can't install the toolkit.
Use the **`alpine-standard`** ISO — its `linux-lts` kernel ships `ne2k-pci`/`8390`, so
networking (and `apk`) work. (Runtime doesn't need networking; only the build does.)

Also verified: v86 boots this fine **without SharedArrayBuffer**, so a plain `php -S`
(no COOP/COEP) is enough to run the build page — it's just slower than a cross-origin-isolated
context.

## Prerequisites

- A real desktop **Chrome/Chromium**. (No SAB needed, but a real browser handles the ~400 MB
  disk buffer + `save_state` comfortably.)
- The build page `lab/_build_vm.html` (gitignored; recreate from the appendix if missing).
- ~1 GB free disk for the ISO + image downloads.

## Phase 1 — download the Alpine **standard x86 (32-bit)** ISO into `lab/`

```bash
cd lab
# STANDARD, not virt (virt has no NIC driver for v86). x86 = 32-bit. Check the dir for the
# current patch level: https://dl-cdn.alpinelinux.org/alpine/v3.19/releases/x86/
curl -L -o alpine-standard-x86.iso \
  https://dl-cdn.alpinelinux.org/alpine/v3.19/releases/x86/alpine-standard-3.19.9-x86.iso
cd ..
```

The build page expects the file named exactly `lab/alpine-standard-x86.iso` (`ISO` const).

## Phase 2 — install Alpine to disk (build page, **install mode**)

1. Serve the repo root: `php -S localhost:8000` (or any static server).
2. Open `http://localhost:8000/lab/_build_vm.html` in Chrome. Leave the file picker **empty**.
3. Click **Start VM**, then **click the black screen** to give it keyboard focus. At the Alpine
   syslinux boot menu press **Enter** (boots the LTS kernel onto the fresh 400 MB disk).
4. Log in as `root` (no password). Confirm networking came up (this is the whole reason for
   the standard ISO): `ip addr` should show `eth0` with a DHCP address. If `eth0` is missing,
   `modprobe ne2k-pci` (or `8390`) then `ifup eth0`. Then run `setup-alpine`:
   - keymap `us` / `us`, hostname `m190lab`, iface `eth0`, IP `dhcp`, no manual net, no proxy
   - **root password** `lab`, timezone `UTC`, no NTP, mirror `1` (or `f` for fastest)
   - user: `no` (we create it by hand below), no SSH
   - **disk** `sda`, use `sys` (installs to disk)
   - answer `y` to erase. When it finishes, **do not refresh** (that wipes the in-memory disk).
5. Install the RE toolkit + configure the guest (still in the VM console):
   ```sh
   apk update
   apk add binutils gdb strace ltrace file vim less util-linux ncurses
   adduser -D -s /bin/sh user && passwd -d user
   echo 'cd /lab' >> /home/user/.profile
   # 9p auto-mount at /lab (how the frontend injects the crackme):
   printf '9p\n9pnet_virtio\n' >> /etc/modules
   mkdir -p /lab
   echo 'host /lab 9p trans=virtio,version=9p2000.L,rw,access=any 0 0' >> /etc/fstab
   # auto-login user on tty1:
   sed -i 's|^tty1.*|tty1::respawn:/sbin/agetty -a user 38400 tty1 linux|' /etc/inittab
   cat > /etc/motd <<'EOF'
   m190 pwn lab — RE toolkit ready. Your crackme is at /lab/crackme.
   EOF
   sync
   ```
   If `apk add` fails, the network **relay is down** — retry later or edit `network_relay_url`
   in `_build_vm.html` to another public v86 relay.
6. Click **💾 Save disk image** → downloads `alpine.img`. Move it into `lab/vm/`.

## Phase 3 — capture the boot snapshot (build page, **capture mode**)

1. Reload `_build_vm.html`. This time **choose the `alpine.img` you just saved** in the file picker.
2. Click **Start VM** → it boots from that disk (no ISO). Wait until the **auto-login `user`
   shell** appears and is responsive (test: `objdump --version`, `mount | grep 9p` shows
   `host on /lab type 9p`).
3. Click **📸 Save state** → downloads `state.bin`. Move it into `lab/vm/`.

> Why both files: `lab.js` resumes from `state.bin` for a ~2–4s boot; `isVmImageAvailable()`
> now requires **both** `alpine.img` and `state.bin` (missing either → download-mode fallback,
> no hang).

## Phase 4 — deploy to the host

Upload **both** files to `lab/vm/` on Hostinger (File Manager or SFTP) — they are gitignored,
so `git pull` will not carry them:

```
lab/vm/alpine.img
lab/vm/state.bin
```

Apache must serve them with **range requests** (it does by default) — v86 streams the disk
lazily, so the 400 MB image is not downloaded all at once. COOP/COEP are already set by
`lab/.htaccess`.

## Phase 5 — verify

```bash
B=https://logansandivar.com/lab
curl -s -o /dev/null -w "alpine.img %{http_code}\n" -r 0-0 $B/vm/alpine.img   # expect 206
curl -s -o /dev/null -w "state.bin  %{http_code}\n" -r 0-0 $B/vm/state.bin    # expect 206
```

Then load `/lab/` in Chrome: it should boot the VM (not "Local RE Mode"), drop `crackme-easy`
at `/lab/crackme`, and `file /lab/crackme` in-guest should say **ELF 32-bit**. Solve-check:
`printf 'm190{w4rmup_x0r_3z_2026}\n' | /lab/crackme` → `yes`.

## Cleanup

```bash
rm lab/_build_vm.html lab/alpine-standard-x86.iso   # both gitignored; safe to delete
```

## Appendix — the build page

The working `lab/_build_vm.html` is gitignored. It is a thin v86 harness with two modes
(install = ISO + blank 400 MB disk; capture = boot a chosen `alpine.img`) and Save
disk-image / Save state buttons, using a V86 config that mirrors `lab.js` so the snapshot
resumes cleanly. Recreate it from git history of this file's introduction, or from the
version last used locally.
