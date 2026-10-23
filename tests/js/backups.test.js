import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { isTooOld, mergeBackups, newestBackup } from '../../js/backups.js'

const now = Date.UTC(2026, 9, 23, 12) / 1000
const day = 86400
const local = [
  { filename: 'basic-2026-10-20-232303.zip', modified: now - 3 * day, size: 10 },
  { filename: 'basic-2026-03-12-091129.zip', modified: now - 200 * day, size: 2 }
]
const remote = [
  { filename: 'basic-2026-10-20-232303.zip', modified: now - 3 * day, size: 10 },
  { filename: 'basic-2026-04-30-130229.zip', modified: now - 170 * day, size: 30 }
]

test('cached server listing from the area: newest of both lists', () => {
  const items = mergeBackups(local, remote)
  assert.equal(items.length, 3)
  assert.equal(newestBackup(items).filename, 'basic-2026-10-20-232303.zip')
  assert.deepEqual(
    items.map(item => [item.local, item.remote]),
    [[true, true], [false, true], [true, false]]
  )
})

test('server loaded lazily: local first, then the merged list', () => {
  assert.equal(newestBackup(mergeBackups(local, null)).filename, 'basic-2026-10-20-232303.zip')

  // A newer copy that only exists on the server becomes the latest once it arrives
  const newer = { filename: 'basic-2026-10-22-020000.zip', modified: now - day, size: 5 }
  const items = mergeBackups(local, [...remote, newer])
  assert.equal(newestBackup(items).filename, newer.filename)
  assert.equal(newestBackup(items).local, false)
})

test('FTP off: latest comes from the local backups', () => {
  assert.equal(newestBackup(mergeBackups(local, null)).modified, now - 3 * day)
  assert.equal(newestBackup(mergeBackups([], null)), null)
})

test('age check with maxBackupAge', () => {
  const latest = newestBackup(mergeBackups(local, remote))
  assert.equal(isTooOld(latest, 0, now), false, '0 turns it off')
  assert.equal(isTooOld(latest, 36, now), true)
  assert.equal(isTooOld(latest, 24 * 4, now), false)
  assert.equal(isTooOld(null, 36, now), true, 'no backup at all')
})

// Every this.<name> in the view must be defined in it: a removed computed
// property does not fail in the production bundle, it just reads undefined
test('the view defines everything it reads from this', () => {
  const view = readFileSync(new URL('../../js/components/FtpBackupView.vue', import.meta.url), 'utf8')
  const used = new Set([...view.matchAll(/this\.([a-zA-Z_]\w*)/g)].map(match => match[1]))
  const missing = [...used].filter(name =>
    !new RegExp(`^\\s+(async\\s+)?${name}\\s*(\\(|:)`, 'm').test(view)
  )
  assert.deepEqual(missing, [])
})
