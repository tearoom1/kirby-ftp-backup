// Pure helpers behind the Panel view, so they can be tested without Kirby

/**
 * Local backups and server copies merged by filename, newest first.
 * `remoteFiles` is null while the server listing is unknown (FTP off, not loaded yet).
 */
export function mergeBackups (localBackups, remoteFiles) {
  const byName = {}

  for (const backup of localBackups || []) {
    byName[backup.filename] = { ...backup, local: true, remote: false }
  }
  for (const file of remoteFiles || []) {
    const entry = byName[file.filename] || {
      filename: file.filename,
      size: file.size,
      modified: file.modified,
      local: false,
      protected: file.protected
    }
    entry.remote = true
    entry.deleteNext = entry.deleteNext || file.deleteNext
    byName[file.filename] = entry
  }

  return Object.values(byName)
    .sort((a, b) => (b.modified - a.modified) || b.filename.localeCompare(a.filename))
}

/**
 * The newest backup of a merged list, wherever it is stored
 */
export function newestBackup (items) {
  return (items && items[0]) || null
}

/**
 * Whether the newest backup is older than `maxBackupAge` hours (0 = off);
 * no backup at all counts as too old when the check is on
 */
export function isTooOld (latest, maxBackupAge, nowSeconds) {
  if (!maxBackupAge) {
    return false
  }
  return !latest || latest.modified < nowSeconds - maxBackupAge * 3600
}
