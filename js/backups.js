// Pure helpers behind the Panel view, so they can be tested without Kirby

/**
 * Local backups and server copies merged by filename, newest first.
 * `remoteFiles` is null while the server listing is unknown (FTP off, not loaded yet).
 */
export function mergeBackups (localBackups, remoteFiles) {
  const byName = {}

  for (const backup of localBackups || []) {
    byName[backup.filename] = { ...backup, local: true, remote: false, deleteNextLocal: Boolean(backup.deleteNext), deleteNextRemote: false }
  }
  for (const file of remoteFiles || []) {
    const entry = byName[file.filename] || {
      filename: file.filename,
      size: file.size,
      modified: file.modified,
      local: false,
      protected: file.protected,
      deleteNextLocal: false
    }
    entry.remote = true
    entry.deleteNextRemote = Boolean(file.deleteNext)
    entry.deleteNext = entry.deleteNextLocal || entry.deleteNextRemote
    byName[file.filename] = entry
  }

  return Object.values(byName)
    .sort((a, b) => (b.modified - a.modified) || b.filename.localeCompare(a.filename))
}

/**
 * Which copies the next run deletes, computed per location (local retention and,
 * with deleteFromFtp, server retention): 'both', 'local', 'remote' or null.
 * 'both' also covers a backup that exists in one place only, since then the
 * whole backup goes. While the server listing is unknown, a flagged local copy is
 * 'local': the server may keep its copy.
 */
export function deleteNote (item, remoteKnown) {
  if (!item.deleteNextLocal && !item.deleteNextRemote) {
    return null
  }
  if (item.local && item.remote) {
    if (item.deleteNextLocal && item.deleteNextRemote) return 'both'
    return item.deleteNextLocal ? 'local' : 'remote'
  }
  if (item.local && !remoteKnown) {
    return 'local'
  }
  return 'both'
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
