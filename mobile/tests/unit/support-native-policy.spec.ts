import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const read = (path: string) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8')

describe('support Android private file policy', () => {
  it('grants Camera only app-specific Pictures and the existing private cache, never external root or durable Data', () => {
    const paths = read('android/app/src/main/res/xml/file_paths.xml')
    expect(paths).toContain('<external-files-path name="camera_images" path="Pictures/" />')
    expect(paths).toContain('<cache-path name="my_cache_images" path="." />')
    expect(paths).not.toMatch(/<external-path|<root-path|<files-path|support-evidence/)
  })
  it('retains non-exported URI-grant authority and disabled backups without enabling public media permissions', () => {
    const manifest = read('android/app/src/main/AndroidManifest.xml')
    expect(manifest).toContain('android:authorities="${applicationId}.fileprovider"')
    expect(manifest).toContain('android:exported="false"')
    expect(manifest).toContain('android:grantUriPermissions="true"')
    expect(manifest).toContain('android:allowBackup="false"')
    expect(manifest).not.toMatch(/WRITE_EXTERNAL_STORAGE|READ_MEDIA_IMAGES|MANAGE_EXTERNAL_STORAGE/)
  })
})
