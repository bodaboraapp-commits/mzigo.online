# Git auto-push watcher

A local background watcher commits and pushes changes from this workspace to:

```text
git@github.com:bodaboraapp-commits/mzigo.online.git
branch main
```

- Script: `_raw/tools/git_autopush.ps1` (local-only, not tracked)
- Log: `_raw/tools/git_autopush.log`
- Git binary: `_raw/tools/mingit/cmd/git.exe`
- SSH transport: `_raw/tools/ssh_wrap.cmd` using the write-enabled deploy key
- Poll interval: 5 seconds, with a short debounce so partially-written files are not committed
- Safety: `.env`, `config-key.php`, logs, backups, dumps and `*.pid` are never committed

To stop it, kill the background PowerShell process running `git_autopush.ps1`.
