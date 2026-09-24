/**
 * x-hotkey directive: run an expression when a key combination is pressed anywhere on the page.
 *
 *   <button x-hotkey.f9="print()">Print (F9)</button>
 *   <form x-hotkey.ctrl.s="$el.requestSubmit()">
 *   <input x-hotkey.f2="$el.focus()">
 *
 * Function keys and ctrl/alt/meta combinations fire even while typing in an input;
 * plain keys are ignored while an input, select or textarea has focus.
 */
export default function hotkey(Alpine) {
    Alpine.directive('hotkey', (el, { modifiers, expression }, { evaluateLater, cleanup }) => {
        const run = evaluateLater(expression || '$el.click()');
        const wanted = new Set(modifiers.map((m) => m.toLowerCase()));
        const combos = ['ctrl', 'alt', 'shift', 'meta'];
        const key = modifiers.find((m) => !combos.includes(m.toLowerCase()))?.toLowerCase();

        const handler = (event) => {
            if (!key || event.key.toLowerCase() !== key) {
                return;
            }

            for (const combo of combos) {
                if (wanted.has(combo) !== event[`${combo}Key`]) {
                    return;
                }
            }

            const isFunctionKey = /^f\d{1,2}$/.test(key) || key === 'escape';
            const hasCombo = combos.some((combo) => wanted.has(combo));
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);

            if (typing && !isFunctionKey && !hasCombo) {
                return;
            }

            event.preventDefault();
            run();
        };

        window.addEventListener('keydown', handler);
        cleanup(() => window.removeEventListener('keydown', handler));
    });
}
