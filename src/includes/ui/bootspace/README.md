# Boot disk space tool fragments

Each `*.php` file here is one tool of the Boot disk space card
(docs/specs/BOOT_DISK_SPACE.md). `ManagerBootSpaceScripts.php` includes them in name
order. A fragment registers its dialog with
`aicliBootSpace.actions['<action id>'] = function (part) { ... }`.

Action ids: `tidy_up`, `remove_old_versions`, `clear_caches`, `move_sessions`,
`bring_back_sessions`, `convert_agents`, `remove_leftover`, `clean_agent_tmp`,
`archive_boot_data`, `remove_boot_data`.
