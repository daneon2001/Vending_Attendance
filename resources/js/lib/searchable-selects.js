import TomSelect from 'tom-select';

const SELECTOR = 'select';
const SKIP_CLASS = 'select-search-skip';
const SELECT_SEARCH_ATTRIBUTE = 'data-select-search';
const ENHANCE_ATTRIBUTE = 'data-enhance-select';
const INITIALIZED_ATTRIBUTE = 'data-select-initialized';
const MODAL_PARENT_SELECTOR = '.modal-content, .modal, [role="dialog"], dialog';
const managedSelects = new Map();
const syncQueue = new Set();
let domObserver = null;
let syncFrameId = null;
let isBooted = false;

const CONTROL_CLASS_PATTERNS = [
    /^(?:dark:)?bg-/,
    /^(?:dark:)?border(?:-|$)/,
    /^(?:dark:)?text-/,
    /^(?:dark:)?shadow(?:-|$)/,
    /^(?:dark:)?ring(?:-|$)/,
    /^(?:dark:)?placeholder:/,
    /^rounded(?:-|$)/,
    /^px-/,
    /^py-/,
    /^pt-/,
    /^pr-/,
    /^pb-/,
    /^pl-/,
    /^p-/,
    /^min-h-/,
    /^h-\d+/,
    /^focus:/,
];

const isElementNode = (node) => node?.nodeType === Node.ELEMENT_NODE;

const shouldMoveClassToControl = (className) =>
    CONTROL_CLASS_PATTERNS.some((pattern) => pattern.test(className));

const isReadonlySelect = (select) => Boolean(select.readOnly || select.hasAttribute('readonly'));

const isExplicitlyEnabledSelect = (select) => {
    if (!(select instanceof HTMLSelectElement)) {
        return false;
    }

    if (select.dataset.selectSearch === 'off' || select.dataset.enhanceSelect === 'false') {
        return false;
    }

    return select.dataset.selectSearch === 'on'
        || select.dataset.enhanceSelect === 'true'
        || select.classList.contains('js-select-search');
};

const getSelectValueSignature = (select) => {
    if (select.multiple) {
        return Array.from(select.selectedOptions).map((option) => option.value).join('|');
    }

    return select.value ?? '';
};

const getSelectOptionsSignature = (select) => Array.from(select.options)
    .map((option) => `${option.value}::${option.text}::${option.disabled ? 1 : 0}::${option.selected ? 1 : 0}`)
    .join('||');

const buildStateSnapshot = (select) => ({
    value: getSelectValueSignature(select),
    options: getSelectOptionsSignature(select),
});

const isStateUnchanged = (previousState, currentState) =>
    previousState?.value === currentState.value && previousState?.options === currentState.options;

const isEligibleSelect = (select) => {
    if (!(select instanceof HTMLSelectElement)) {
        return false;
    }

    if (select.tomselect || managedSelects.has(select) || select.hasAttribute(INITIALIZED_ATTRIBUTE)) {
        return false;
    }

    if (select.multiple || Number(select.getAttribute('size') || 0) > 1) {
        return false;
    }

    if (select.disabled || isReadonlySelect(select)) {
        return false;
    }

    if (select.classList.contains(SKIP_CLASS) || !isExplicitlyEnabledSelect(select)) {
        return false;
    }

    if (select.closest('.ts-wrapper') || select.closest('[data-select-search-root="ignore"]')) {
        return false;
    }

    return true;
};

const shouldDestroyInstance = (select) => {
    if (!(select instanceof HTMLSelectElement)) {
        return false;
    }

    if (!document.contains(select)) {
        return true;
    }

    return select.multiple
        || Number(select.getAttribute('size') || 0) > 1
        || select.disabled
        || isReadonlySelect(select)
        || !isExplicitlyEnabledSelect(select)
        || select.classList.contains(SKIP_CLASS);
};

const moveVisualClassesToControl = (select, instance) => {
    Array.from(select.classList).forEach((className) => {
        if (!shouldMoveClassToControl(className)) {
            return;
        }

        instance.wrapper.classList.remove(className);
        instance.control.classList.add(className);
    });
};

const resolvePlaceholder = (select) => {
    const explicitPlaceholder = select.getAttribute('placeholder') || select.dataset.selectPlaceholder;
    if (explicitPlaceholder) {
        return explicitPlaceholder;
    }

    const emptyOption = Array.from(select.options).find((option) => option.value === '');
    return emptyOption?.text?.trim() || 'Selecciona una opcion';
};

const resolveDropdownParent = (select) =>
    select.closest(MODAL_PARENT_SELECTOR)
    || select.closest('.fixed')
    || document.body;

const ensureDropdownParentContext = (parent) => {
    if (!(parent instanceof HTMLElement) || parent === document.body) {
        return parent;
    }

    if (window.getComputedStyle(parent).position === 'static') {
        parent.classList.add('fortia-select-search-host');
    }

    return parent;
};

const positionDropdownInContext = (instance, parent) => {
    if (!(parent instanceof HTMLElement)) {
        return;
    }

    const controlRect = instance.control.getBoundingClientRect();
    const parentRect = parent.getBoundingClientRect();
    const top = controlRect.bottom - parentRect.top + parent.scrollTop;
    const left = controlRect.left - parentRect.left + parent.scrollLeft;

    Object.assign(instance.dropdown.style, {
        width: `${controlRect.width}px`,
        top: `${top}px`,
        left: `${left}px`,
    });
};

const destroyManagedSelect = (select) => {
    const entry = managedSelects.get(select);
    if (!entry) {
        return;
    }

    entry.instance.destroy();
    select.removeAttribute(INITIALIZED_ATTRIBUTE);
    managedSelects.delete(select);
    syncQueue.delete(select);
};

const syncManagedSelect = (select) => {
    const entry = managedSelects.get(select);
    if (!entry) {
        return;
    }

    if (shouldDestroyInstance(select)) {
        destroyManagedSelect(select);
        return;
    }

    const currentState = buildStateSnapshot(select);
    if (isStateUnchanged(entry.state, currentState)) {
        return;
    }

    entry.instance.sync();
    entry.instance.refreshOptions(false);
    entry.state = buildStateSnapshot(select);
};

const queueSelectSync = (select) => {
    if (!managedSelects.has(select)) {
        return;
    }

    syncQueue.add(select);

    if (syncFrameId !== null) {
        return;
    }

    syncFrameId = window.requestAnimationFrame(() => {
        syncFrameId = null;

        Array.from(syncQueue).forEach((queuedSelect) => {
            syncQueue.delete(queuedSelect);
            syncManagedSelect(queuedSelect);
        });
    });
};

const enhanceSelect = (select) => {
    if (!isEligibleSelect(select)) {
        return;
    }

    const dropdownParent = ensureDropdownParentContext(resolveDropdownParent(select));
    const instance = new TomSelect(select, {
        allowEmptyOption: true,
        copyClassesToDropdown: false,
        create: false,
        dropdownParent,
        maxOptions: 250,
        persist: false,
        plugins: ['change_listener', 'dropdown_input'],
        placeholder: resolvePlaceholder(select),
        render: {
            no_results() {
                return '<div class="no-results">Sin coincidencias</div>';
            },
        },
        searchField: ['text'],
        selectOnTab: true,
    });

    if (dropdownParent !== document.body) {
        instance.positionDropdown = () => {
            positionDropdownInContext(instance, dropdownParent);
        };
    }

    instance.wrapper.classList.add('fortia-select-search');
    instance.control.classList.add('fortia-select-search__control');
    instance.dropdown.classList.add('fortia-select-search__dropdown');
    select.setAttribute(INITIALIZED_ATTRIBUTE, 'true');
    moveVisualClassesToControl(select, instance);

    const syncOnInteract = () => queueSelectSync(select);
    instance.wrapper.addEventListener('mousedown', syncOnInteract, { passive: true });
    instance.wrapper.addEventListener('focusin', syncOnInteract);

    instance.on('dropdown_open', () => {
        instance.positionDropdown();
        queueSelectSync(select);
    });

    managedSelects.set(select, {
        instance,
        state: buildStateSnapshot(select),
    });
};

const collectSelects = (root) => {
    if (root instanceof HTMLSelectElement) {
        return [root];
    }

    if (!isElementNode(root)) {
        return [];
    }

    return Array.from(root.querySelectorAll(SELECTOR));
};

const initSelects = (root = document.body) => {
    collectSelects(root).forEach((select) => {
        if (managedSelects.has(select) && shouldDestroyInstance(select)) {
            destroyManagedSelect(select);
            return;
        }

        if (managedSelects.has(select)) {
            queueSelectSync(select);
            return;
        }

        enhanceSelect(select);
    });
};

const teardownRemovedSelects = (root) => {
    collectSelects(root).forEach((select) => {
        if (managedSelects.has(select)) {
            destroyManagedSelect(select);
        }
    });
};

const handleMutation = (mutation) => {
    if (mutation.type === 'attributes' && mutation.target instanceof HTMLSelectElement) {
        if (shouldDestroyInstance(mutation.target)) {
            destroyManagedSelect(mutation.target);
            return;
        }

        if (managedSelects.has(mutation.target)) {
            queueSelectSync(mutation.target);
            return;
        }

        enhanceSelect(mutation.target);
        return;
    }

    if (mutation.target instanceof HTMLSelectElement) {
        queueSelectSync(mutation.target);
    }

    mutation.addedNodes.forEach((node) => {
        if (!isElementNode(node)) {
            return;
        }

        initSelects(node);
    });

    mutation.removedNodes.forEach((node) => {
        if (!isElementNode(node)) {
            return;
        }

        teardownRemovedSelects(node);
    });
};

const startObserver = () => {
    if (domObserver) {
        return;
    }

    domObserver = new MutationObserver((mutations) => {
        mutations.forEach(handleMutation);
    });

    domObserver.observe(document.body, {
        attributeFilter: ['class', 'disabled', 'multiple', 'readonly', SELECT_SEARCH_ATTRIBUTE, ENHANCE_ATTRIBUTE],
        attributes: true,
        childList: true,
        subtree: true,
    });
};

export const bootSearchableSelects = () => {
    if (isBooted || typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    isBooted = true;

    const start = () => {
        initSelects(document.body);
        startObserver();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
        return;
    }

    start();
};

export const refreshSearchableSelects = (root = document.body) => {
    initSelects(root);
};

if (typeof window !== 'undefined') {
    window.FortiaSearchableSelects = {
        refresh: refreshSearchableSelects,
    };
}
