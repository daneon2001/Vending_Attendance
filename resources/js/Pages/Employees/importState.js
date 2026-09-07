export const classificationLabels = {
    VALID_NEW: 'Nuevo', VALID_UPDATE: 'Actualizado', UNCHANGED: 'Sin cambios',
    INVALID: 'Con errores', DUPLICATE_FILE: 'Duplicado', CONFLICT_SOURCE: 'Conflicto de fuente',
};

export const emptyImportState = () => ({ step: 1, file: null, preview: null, confirmed: false, error: '', busy: false });

export function selectImportFile(state, file) {
    return { ...emptyImportState(), file };
}

export function canApplyImport(state) {
    return !state.busy && state.confirmed === true && state.preview?.status === 'PREVIEW'
        && typeof state.preview?.uuid === 'string' && typeof state.preview?.preview_hash === 'string'
        && Number(state.preview.summary?.valid_new ?? 0) + Number(state.preview.summary?.valid_update ?? 0) > 0;
}

export function confirmationPayload(state) {
    if (!canApplyImport(state)) throw new Error('Import confirmation is not ready.');
    return { confirmed: true, preview_hash: state.preview.preview_hash };
}

export function receivePreview(state, preview) {
    const samePreview = preview?.uuid === state.preview?.uuid && preview?.preview_hash === state.preview?.preview_hash;
    const step = preview?.status === 'COMPLETED' ? 5 : samePreview ? Math.max(2, Math.min(state.step ?? 2, 4)) : 2;
    return { ...state, preview, step, confirmed: false, busy: false, error: '' };
}


export const importSteps = ['Seleccionar archivo', 'Revisar columnas', 'Validar información', 'Confirmar cambios', 'Resultado'];
export function navigateImport(state, step) {
    if (state.busy || state.preview?.status === 'COMPLETED' || step < 1 || step > 4 || (step > 1 && !state.preview)) return state;
    return { ...state, step, confirmed: false };
}
