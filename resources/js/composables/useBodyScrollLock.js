import { onBeforeUnmount, watch } from 'vue';

let lockCount = 0;
let previousOverflow = '';

const applyLock = () => {
    if (typeof document === 'undefined') return;

    if (lockCount > 0) {
        if (lockCount === 1) {
            previousOverflow = document.body.style.overflow;
        }
        document.body.style.overflow = 'hidden';
        return;
    }

    document.body.style.overflow = previousOverflow || '';
};

const lockBody = () => {
    lockCount += 1;
    applyLock();
};

const unlockBody = () => {
    lockCount = Math.max(lockCount - 1, 0);
    applyLock();
};

export const useBodyScrollLock = (source) => {
    let locked = false;

    watch(
        source,
        (isOpen) => {
            if (isOpen && !locked) {
                lockBody();
                locked = true;
                return;
            }

            if (!isOpen && locked) {
                unlockBody();
                locked = false;
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        if (locked) {
            unlockBody();
            locked = false;
        }
    });
};
