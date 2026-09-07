import test from 'node:test';
import assert from 'node:assert/strict';
import { canApplyImport, confirmationPayload, emptyImportState, receivePreview, selectImportFile } from '../../resources/js/Pages/Employees/importState.js';

const preview = { uuid: 'test-run', preview_hash: 'a'.repeat(64), status: 'PREVIEW', summary: { valid_new: 1, valid_update: 0 } };

test('selecting a file cannot apply data or reuse previous confirmation', () => {
    const state = selectImportFile({ ...emptyImportState(), preview, confirmed: true }, { name: 'new.csv' });
    assert.equal(state.preview, null);
    assert.equal(state.confirmed, false);
    assert.equal(canApplyImport(state), false);
});
test('a staged preview requires explicit confirmation and sends no client-provided rows', () => {
    let state = receivePreview(emptyImportState(), preview);
    assert.throws(() => confirmationPayload(state));
    state.confirmed = true;
    assert.deepEqual(confirmationPayload(state), { confirmed: true, preview_hash: preview.preview_hash });
    state.busy = true;
    assert.equal(canApplyImport(state), false);
});
test('stale or paginated refreshed previews invalidate previous approval', () => {
    const state = receivePreview({ ...emptyImportState(), preview, confirmed: true }, { ...preview, preview_hash: 'b'.repeat(64) });
    assert.equal(canApplyImport(state), false);
    assert.equal(state.confirmed, false);
});
test('empty changes, completed, failed and expired runs cannot be applied', () => {
    for (const status of ['COMPLETED', 'FAILED', 'EXPIRED', 'APPLYING']) assert.equal(canApplyImport({ preview: { ...preview, status }, confirmed: true, busy: false }), false);
    assert.equal(canApplyImport({ preview: { ...preview, summary: { invalid: 9, conflicts: 1 } }, confirmed: true, busy: false }), false);
});
