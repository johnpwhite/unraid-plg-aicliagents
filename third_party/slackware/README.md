# Slackware tmux package

This folder holds the unmodified Slackware 3.8 package and its signature. Both files came from the same directory on Slackware's official mirror. The package was not unpacked or changed. The installer checks the package SHA-256 before it extracts tmux. It uses the official Slackware-current mirror first. It uses this copy if Slackware removes the package from that rolling mirror.

Package source:

`https://mirrors.slackware.com/slackware/slackware64-current/slackware64/ap/tmux-3.8-x86_64-1.txz`

Slackware signed the package on 2026-10-09. The signing key's primary fingerprint is:

`EC56 49DA 401E 22AB FA67 36EF 6A44 63C0 4010 2233`

To verify the files, download Slackware's key from the official mirror. Check that its primary fingerprint matches the value above. Then run these commands in this folder:

```sh
curl -fsSLO https://mirrors.slackware.com/slackware/slackware64-15.0/GPG-KEY
gpg --import GPG-KEY
gpg --verify tmux-3.8-x86_64-1.txz.asc tmux-3.8-x86_64-1.txz
sha256sum tmux-3.8-x86_64-1.txz
```

Expected SHA-256:

`9f246361fe6a9c34a9fb1ba4a0e94b538b70cf82767c5273daf215bbd3a89d33`
